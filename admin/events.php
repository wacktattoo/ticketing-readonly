<?php
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';
ensure_admin();
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

/* ---------- INLINE DELETE (MUSÍ BÝT PŘED SELECTY) ---------- */
if (($_POST['action'] ?? '') === 'delete') {
  $csrf = $_POST['csrf'] ?? '';
  if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
    header('Location: /admin/?err=csrf'); exit;
  }

  // NEPŘETYPOVÁVAT na (int) – může to být UUID / string
  $id = $_POST['id'] ?? '';

  if ($id === '' ) { header('Location: /admin/?err=bad_id'); exit; }

  $db = db();
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  try {
    $stmt = $db->prepare('DELETE FROM events WHERE id = :id'); // bez LIMIT 1 kvůli kompatibilitě
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() > 0) {
      header('Location: /admin/?deleted=1'); exit;
    } else {
      // nic se nesmazalo – nejspíš id neodpovídá (typ/obsah)
      header('Location: /admin/?err=not_found'); exit;
    }
  } catch (Throwable $ex) {
    header('Location: /admin/?err=sql'); exit;
  }
}


// ---- Filters / search ---------------------------------
$q       = isset($_GET['q']) ? trim($_GET['q']) : '';
$status  = isset($_GET['status']) ? trim($_GET['status']) : 'all'; // default: all
$sort    = isset($_GET['sort']) ? trim($_GET['sort']) : 'created_desc';

$sortSql = match($sort) {
  'date_asc'   => 'e.starts_at ASC',
  'date_desc'  => 'e.starts_at DESC',
  'title_asc'  => 'e.title ASC',
  'title_desc' => 'e.title DESC',
  default      => 'e.created_at DESC'
};

$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(e.title LIKE :q OR e.venue_name LIKE :q OR e.address LIKE :q)";
  $params[':q'] = "%$q%";
}

$validStatuses = ['draft','on_sale','sold_out','archived'];
// pokud status = all/prázdný → NEfiltrujeme (zobrazí se i archivované)
if ($status !== '' && $status !== 'all' && in_array($status, $validStatuses, true)) {
  $where[] = "e.status = :status";
  $params[':status'] = $status;
}

$whereSql = $where ? "WHERE ".implode(" AND ", $where) : "";


// ---- Fetch events -------------------------------------
// ⬇️ Přidán e.slug
$sql = "
  SELECT e.id, e.slug, e.title, e.starts_at, e.status, e.cover_image_url, e.venue_name, e.address
  FROM events e
  $whereSql
  ORDER BY $sortSql
  LIMIT 200
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

// ---- Status counters for header chips -----------------
$counts = db()->query("
  SELECT status, COUNT(*) cnt
  FROM events
  GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate($dt) {
  if(!$dt) return '';
  try { $d = new DateTime($dt); return $d->format('d.m.Y H:i'); } catch(Exception $e){ return $dt; }
}

$blue = '#2563eb';
?>
<?php $admin_show_back = false; // schovat „Zpět“
include __DIR__.'/_header.php'; ?>
<style>
  
.card-actions {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: auto;
}
.card.is-archived {
  opacity: .6;
  filter: grayscale(85%);
}
.card.is-archived .btn-action { pointer-events: auto; }
/* Mini progress v kartě akce */
.mini-progress{border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fff;    margin: 0 5px;}
.mini-progress .head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13px;color:#475569}
.mini-progress .nums{font-variant-numeric:tabular-nums}
.mini-progress .bar{position:relative;height:8px;background:#eef2ff;border:1px solid #e5e7eb;border-radius:999px;overflow:hidden}
.mini-progress .bar > span{position:absolute;left:0;top:0;bottom:0;width:0;transition:width .5s cubic-bezier(.2,.8,.2,1);background:linear-gradient(90deg,#2563eb,#1d4ed8)}
.mini-progress .pill{display:inline-flex;gap:6px;align-items:center;padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;background:#f8fafc;color:#334155;font-weight:600}
.mini-progress .pill .dot{width:6px;height:6px;border-radius:50%;background:#2563eb}

.card-buttons {
  display: flex;
  flex-direction: column;
  flex-wrap: column;
  gap: 8px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
/* Nová akce tlačítko – decentnější testuju */
.btn-new-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #2563eb;
  color: #fff;
  padding: 8px 14px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  text-decoration: none;
  border: 1px solid #2563eb;
  transition: background 0.25s ease, box-shadow 0.25s ease;
}
.btn-new-action:hover {
  background: #1d4ed8;
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.15);
}
.btn-new-action i {
  font-size: 14px;
}

/* Status chips – decentní styl */
.status-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0 0 16px;
}

.status-chips .chip{
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; font-size:13px; font-weight:500; line-height:1.2;
  border-radius:999px; text-decoration:none; /* <- je to link */
  background:var(--bg); color:var(--fg); border:1px solid var(--border);
  transition:background .2s ease, box-shadow .2s ease, transform .06s ease;
}
.status-chips .chip .dot{ width:7px; height:7px; border-radius:50%; background:var(--fg); }
.status-chips .chip .count{ opacity:.7; font-size:12px; }
.status-chips .chip:hover{ background:#f3f4f6; box-shadow:0 2px 6px rgba(0,0,0,.05); transform:translateY(-1px); }
.status-chips .chip.active{
  background:#eef2ff; border-color:#c7d2fe; color:#1e40af;
}
.status-chips .chip.active .dot{ background:#1e40af; }

.btn-action {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 6px 8px;
  border: 1px solid #2563eb;
  color: #2563eb;
  background: #fff;
  border-radius: 8px;
  font-size: 14px;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.25s ease;
}

.btn-action:hover {
  background: #2563eb;
  color: #fff;
}
.btn-zobrazit {
  flex-shrink: 0;
  padding: 6px 8px;
  color: #585858ff;
  font-size: 15px;
  gap: 7px;
  display: inline-flex;
  align-items: center;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.25s ease;
}

.btn-zobrazit:hover {
  color: #4145bfff;
}
.card-secondary-actions{
      display: flex;
    align-items: center;
    gap: 20px;
    justify-content: center;
    flex-direction: row;
    flex-wrap: nowrap;
}

.card-secondary-actions .inline-delete{
  margin:0; padding:0;
}

.btn-delete{
  background:none;
  border:none;
  color:#dc2626; /* červená */
  cursor:pointer;
  font-size:15px;
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:4px 0;
}
.btn-delete:hover{
  background: none !important;
  color: #555 !important;
}

.btn-copy-link {
  background: none;
  border: none;
  color: #555;
  cursor: pointer;
  font-size: 15px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 0;
  transition: color 0.25s ease;
}

.btn-copy-link:hover {
  background: none !important;
  color: #555 !important;
}
</style>
<h1 style="display:flex;align-items:center;gap:12px;justify-content:space-between;margin:12px 0 10px">
  <span>Akce</span>
  <a class="btn-new-action" href="/admin/event_edit.php">
    <i class="fa-solid fa-plus"></i> Nová akce
  </a>
</h1>
<?php if(isset($_GET['deleted'])): ?>
  <div class="alert" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:10px 12px;margin:8px 0;">
    Akce byla <strong>smazána</strong>.
  </div>
<?php elseif(isset($_GET['err'])): ?>
  <div class="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d;border-radius:10px;padding:10px 12px;margin:8px 0;">
    Mazání se nepodařilo (kód: <?=h($_GET['err'])?>).
  </div>
<?php endif; ?>
<!-- Top controls: search + filters -->
<form method="get" class="admin-filters" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:8px 0 18px;">
  <input type="text" name="q" value="<?=h($q)?>" placeholder="Hledat název, místo nebo adresu..." 
         style="flex:1;min-width:240px;padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff">

 <select name="status" style="padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff">
  <option value="all" <?= ($status==='' || $status==='all')?'selected':'' ?>>Všechny stavy</option>
  <?php foreach (['draft'=>'Koncept','on_sale'=>'V prodeji','sold_out'=>'Vyprodáno','archived'=>'Archiv'] as $k=>$label): ?>
    <option value="<?=$k?>" <?= $status===$k?'selected':'' ?>><?=$label?></option>
  <?php endforeach; ?>
</select>

  <select name="sort" style="padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff">
    <option value="created_desc" <?=$sort==='created_desc'?'selected':''?>>Nejnovější</option>
    <option value="date_asc"     <?=$sort==='date_asc'?'selected':''?>>Nejbližší datum akce ↑</option>
    <option value="date_desc"    <?=$sort==='date_desc'?'selected':''?>>Nejpozdější datum akce ↓</option>
    <option value="title_asc"    <?=$sort==='title_asc'?'selected':''?>>Název A–Z</option>
    <option value="title_desc"   <?=$sort==='title_desc'?'selected':''?>>Název Z–A</option>
  </select>

  <button class="btn" type="submit" style="background:<?=$blue?>;color:#fff;border-color:<?=$blue?>">Filtrovat</button>
  <?php if($q!=='' || $status!=='' || $sort!=='created_desc'): ?>
    <a class="btn" href="/admin/">Reset</a>
  <?php endif; ?>
</form>

<!-- Status chips -->
<?php
// počty dle statusů
$counts = db()->query("
  SELECT status, COUNT(*) cnt
  FROM events
  GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$totalCount = array_sum($counts ?: []);
$chips = [
  'all'      => ['Vše',       '#111827', '#f3f4f6', '#e5e7eb', $totalCount],
  'on_sale'  => ['V prodeji', '#2563eb', '#f0f5ff', '#c7d2fe', $counts['on_sale']  ?? 0],
  'sold_out' => ['Vyprodáno', '#991b1b', '#fef2f2', '#fecaca', $counts['sold_out'] ?? 0],
  'draft'    => ['Koncept',   '#6b7280', '#f9fafb', '#d1d5db', $counts['draft']    ?? 0],
  'archived' => ['Archiv',    '#374151', '#f9fafb', '#d1d5db', $counts['archived'] ?? 0],
];

// zachovej q/sort v URL
$base = [];
if ($q !== '') $base['q'] = $q;
if ($sort !== 'created_desc') $base['sort'] = $sort;

$currentChip = ($status==='' ? 'all' : $status);
?>
<div class="status-chips">
  <?php foreach($chips as $key => [$label,$fg,$bg,$border,$cnt]):
        $qs = $base; $qs['status'] = $key; $href = '?'.http_build_query($qs);
        $isActive = ($currentChip === $key);
  ?>
    <a class="chip<?= $isActive ? ' active' : '' ?>"
       href="<?= h($href) ?>"
       style="--fg:<?= $fg ?>;--bg:<?= $bg ?>;--border:<?= $border ?>">
      <span class="dot"></span>
      <span class="label"><?= h($label) ?></span>
      <span class="count">(<?= (int)$cnt ?>)</span>
    </a>
  <?php endforeach; ?>
</div>


<!-- Cards grid -->
<?php if (!$events): ?>
  <div class="alert">Nenalezeny žádné akce.</div>
<?php else: ?>
  <div class="cards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
    <?php foreach($events as $e):
  $cover = $e['cover_image_url'] ?: '/assets/placeholder-cover.jpg';
  $date  = fmtDate($e['starts_at']);
  $statusMap = [
    'draft'    => ['Koncept','#6b7280','#f3f4f6'],
    'on_sale'  => ['V prodeji',$blue,'#dbeafe'],
    'sold_out' => ['Vyprodáno','#991b1b','#fee2e2'],
    'archived' => ['Archiv','#374151','#e5e7eb'],
  ];
  [$statusLabel,$statusFG,$statusBG] = $statusMap[$e['status']] ?? ['Neznámý','#111','#eee'];

  // ⬇️ URL na veřejný detail: preferuj slug, jinak ID
  $slug = trim((string)($e['slug'] ?? ''));
  $publicUrl = '/e/' . ($slug !== '' ? rawurlencode($slug) : rawurlencode((string)$e['id']));

  // ⬇️ Přidáno — kontrola archivace
  $isArchived = ($e['status'] === 'archived');
?>
<article class="card<?= $isArchived ? ' is-archived' : '' ?>"
         style="background:#fff;border:1px solid #eee;border-radius:14px;overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.04);display:flex;flex-direction:column;transition:transform .15s ease, box-shadow .15s ease;">
      <div class="thumb" style="position:relative;aspect-ratio:16/9;background:#f3f3f3;overflow:hidden;">
        <img src="<?=h($cover)?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;">
        <div style="position:absolute;top:10px;left:10px;padding:6px 10px;border-radius:999px;background:<?=$statusBG?>;color:<?=$statusFG?>;display:inline-flex;align-items:center;gap:8px;font-weight:600;border:1px solid rgba(0,0,0,0.06)">
          <span style="width:8px;height:8px;border-radius:999px;background:<?=$statusFG?>"></span>
          <?=$statusLabel?>
        </div>
      </div>

      <div style="padding:12px 12px 6px;">
        <h3 style="margin:0 0 6px;font-size:18px;line-height:1.2;"><?=h($e['title'])?></h3>
        <div style="display:flex;flex-direction:column;gap:4px;color:#555;font-size:14px;">
          <div><strong>Datum:</strong> <?=$date?></div>
          <?php if(!empty($e['venue_name'])): ?>
            <div><strong>Místo:</strong> <?=h($e['venue_name'])?></div>
          <?php endif; ?>
          <?php if(!empty($e['address'])): ?>
            <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong>Adresa:</strong> <?=h($e['address'])?></div>
          <?php endif; ?>
        </div>
      </div>
<!-- Mini obsazenost -->
<div class="mini-progress" data-event-id="<?= h($e['id']) ?>">
  <div class="head">
    <div class="pill" data-mode-pill>
      <span class="dot"></span>
      <span class="label">Načítám…</span>
    </div>
    <div class="nums">
      <span class="sold">0</span> /
      <span class="cap">0</span>
      (<span class="pct">0</span>%)
    </div>
  </div>
  <div class="bar"><span style="width:0%"></span></div>
</div>

      <div style="padding:10px 12px 12px;margin-top:auto;display:flex;flex-direction:column;gap:8px;">
                <div class="card-actions">
          <div class="card-buttons">
            
          <a class="btn-action" href="/admin/event_detail.php?id=<?= h($e['id']) ?>">
              <i class="fa-solid fa-circle-info"></i>
              Detail
            </a>

            <a class="btn-action" href="/admin/tickets_choice.php?event=<?= h($e['id']) ?>">
              <i class="fa-solid fa-chair"></i>
              Vstupenky a sedadla
            </a>

          <!-- ⬇️ Kopírovat odkaz používá slug|id -->
<div class="card-secondary-actions">
    <a class="btn-zobrazit" href="<?=$publicUrl?>" target="_blank">
              <i class="fa-regular fa-eye"></i>Zobrazit</a>

<button class="btn-delete" type="button"
        data-id="<?=$e['id']?>"
        data-title="<?=h($e['title'])?>">
  <i class="fa-regular fa-trash-can"></i>
  <span>Smazat</span>
</div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
document.querySelectorAll('.card').forEach(card=>{
  card.addEventListener('mouseenter',()=>{ card.style.transform='translateY(-2px)'; card.style.boxShadow='0 6px 14px rgba(0,0,0,.08)'; });
  card.addEventListener('mouseleave',()=>{ card.style.transform=''; card.style.boxShadow='0 2px 6px rgba(0,0,0,.04)'; });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.addEventListener('click', async function(e){
    // kopírování odkazu (ponechávám)
    const copy = e.target.closest('.btn-copy-link');
    if (copy) {
      const url = new URL(copy.getAttribute('data-url'), location.origin).toString();
      try {
        await navigator.clipboard.writeText(url);
        const icon = copy.querySelector('i'), text = copy.querySelector('span');
        if (icon) icon.className = "fa-solid fa-check";
        if (text) text.textContent = "Zkopírováno!";
        setTimeout(()=>{ if (icon) icon.className="fa-regular fa-copy"; if (text) text.textContent="Kopírovat odkaz"; },1500);
      } catch { prompt('Zkopíruj odkaz ručně:', url); }
      return;
    }
  }, false);
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
  document.addEventListener('click', function(e){
    const del = e.target.closest('.btn-delete');
    if (!del) return;

    const id = del.getAttribute('data-id');
    const title = del.getAttribute('data-title') || '';
    if (!id) return;

    if (confirm(`Opravdu chceš smazat akci:\n\n"${title}"\n\nTato akce bude nenávratně odstraněna.`)) {
      const form = document.getElementById('deleteForm');
      const hid  = document.getElementById('deleteFormId');
      if (!form || !hid) { console.error('Chybí #deleteForm / #deleteFormId'); return; }
      hid.value = id;      // NEpřetypovávat
      form.submit();
    }
  }, false);
});
</script>


<form id="deleteForm" method="post" action="" style="display:none">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteFormId">
  <input type="hidden" name="csrf" value="<?=$_SESSION['csrf']?>">
</form>
<script>
(function(){
  // Lazy načítání stats jen pro karty, které jsou v dohledu
  const blocks = document.querySelectorAll('.mini-progress[data-event-id]');
  if (!blocks.length) return;

  function render(block, data){
    const sold = +data.sold || 0;
    const cap  = +data.capacity || 0;
    const pct  = cap > 0 ? Math.round(sold*100/cap) : 0;

    block.querySelector('.sold').textContent = sold.toLocaleString('cs-CZ');
    block.querySelector('.cap').textContent  = cap.toLocaleString('cs-CZ');
    block.querySelector('.pct').textContent  = pct;
    const bar = block.querySelector('.bar > span');
    if (bar) bar.style.width = Math.max(0, Math.min(100, pct)) + '%';

    const pill = block.querySelector('[data-mode-pill]');
    if (pill){
      const label = pill.querySelector('.label');
      const dot   = pill.querySelector('.dot');
      if (label) label.textContent = (data.mode === 'seatmap') ? 'Sedadla' : 'Volné vstupenky (GA)';
      if (dot)   dot.style.background = (data.mode === 'seatmap') ? '#0ea5e9' : '#2563eb';
    }
  }

  async function load(block){
    const id = block.getAttribute('data-event-id');
    try{
      const res = await fetch(`/admin/event_stats.php?id=${encodeURIComponent(id)}`, {cache:'no-store'});
      if(!res.ok) return;
      const json = await res.json();
      if (json && !json.error) render(block, json);
    }catch(_){}
  }

  // IntersectionObserver pro lazy fetch
  const io = new IntersectionObserver((entries)=>{
    entries.forEach(e=>{
      if (e.isIntersecting){
        load(e.target);
        // opakuj každých 15 s, ať se to hezky aktualizuje
        const t = setInterval(()=> load(e.target), 15000);
        e.target._ti = t;
        io.unobserve(e.target);
      }
    });
  }, {rootMargin: '200px 0px'});

  blocks.forEach(b=> io.observe(b));
  window.addEventListener('beforeunload', ()=>{
    blocks.forEach(b=> b._ti && clearInterval(b._ti));
  });
})();
</script>


<?php include __DIR__.'/_footer.php'; ?>
