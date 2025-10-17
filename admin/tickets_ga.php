<?php
// /admin/tickets_ga.php
require_once __DIR__ . '/../inc/db.php';
// require_once __DIR__ . '/admin_auth.php'; // pokud máš auth

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$eventId = $_GET['event'] ?? '';
if ($eventId === '') {
  http_response_code(400);
  echo 'Chybí parametr event';
  exit;
}

// načti akci (kvůli názvu a existenci)
$st = db()->prepare("SELECT id, title, slug, status FROM events WHERE id=? LIMIT 1");
$st->execute([$eventId]);
$event = $st->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); echo 'Akce nenalezena'; exit; }

// flash hlášky (velmi jednoduché)
$flash = ['ok'=>null, 'err'=>null];

// POST akce
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  try {
    if ($action === 'create' || $action === 'update') {
      $id        = isset($_POST['id']) ? (int)$_POST['id'] : null;
      $code      = trim((string)($_POST['code'] ?? ''));
      $name      = trim((string)($_POST['name'] ?? ''));
      $priceCZK  = (int)($_POST['price_czk'] ?? 0);
      $priceEUR  = (int)($_POST['price_eur'] ?? 0);
      $color     = trim((string)($_POST['color'] ?? ''));
      $capacity  = max(0, (int)($_POST['capacity'] ?? 0));

      if ($code === '' || $name === '') { throw new RuntimeException('Kód i název jsou povinné.'); }
      if ($priceCZK < 0 || $priceEUR < 0) { throw new RuntimeException('Cena nesmí být záporná.'); }

      $pricesJson = json_encode(['CZK'=>$priceCZK, 'EUR'=>$priceEUR], JSON_UNESCAPED_UNICODE);

      if ($action === 'create') {
        $ins = db()->prepare("
          INSERT INTO event_ticket_types (event_id, code, name, prices_json, color, capacity)
          VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute([$event['id'], $code, $name, $pricesJson, $color ?: null, $capacity]);
        $flash['ok'] = 'Typ vstupenky byl přidán.';
      } else {
        if (!$id) { throw new RuntimeException('Chybí ID typu pro uložení.'); }
        $upd = db()->prepare("
          UPDATE event_ticket_types
             SET code=?, name=?, prices_json=?, color=?, capacity=?
           WHERE id=? AND event_id=?
        ");
        $upd->execute([$code, $name, $pricesJson, $color ?: null, $capacity, $id, $event['id']]);
        $flash['ok'] = 'Typ vstupenky byl uložen.';
      }

      // === DŮLEŽITÉ: přepni akci do GA režimu ===
$setMode = db()->prepare("
  UPDATE events
     SET selling_mode='ga', seating_mode='ga' -- seating_mode nech klidně pro kompatibilitu
   WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', '')))
   LIMIT 1
");
$setMode->execute([$event['id'], $event['id']]);


    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if (!$id) { throw new RuntimeException('Chybí ID typu pro smazání.'); }
      $del = db()->prepare("DELETE FROM event_ticket_types WHERE id=? AND event_id=?");
      $del->execute([$id, $event['id']]);
      $flash['ok'] = 'Typ vstupenky byl smazán.';

      // === Drž režim GA i po mazání (jednoduché pravidlo) ===
      $setMode = db()->prepare("
        UPDATE events
           SET seating_mode='ga'
         WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', '')))
         LIMIT 1
      ");
      $setMode->execute([$event['id'], $event['id']]);

      // (Pokud chceš chytřejší chování: když po mazání nezůstane žádný GA typ,
      // můžeš tady zjistit COUNT(*) a případně přepnout na 'seatmap'.)
    } else {
      // neznámá akce — nic
    }
  } catch (PDOException $e) {
    if ((int)$e->errorInfo[1] === 1062) {
      $flash['err'] = 'Kód musí být v rámci akce unikátní.';
    } else {
      $flash['err'] = 'Chyba DB: '.$e->getMessage();
    }
  } catch (Throwable $e) {
    $flash['err'] = $e->getMessage();
  }
}


// znovu načti seznam typů
$tt = db()->prepare("
  SELECT id, code, name, prices_json, color, capacity, sold
  FROM event_ticket_types
  WHERE event_id=?
  ORDER BY id ASC
");
$tt->execute([$event['id']]);
$types = $tt->fetchAll(PDO::FETCH_ASSOC);

// helper pro ceny
function prices($row){
  $p = ['CZK'=>0,'EUR'=>0];
  if (!empty($row['prices_json'])) {
    try {
      $j = json_decode($row['prices_json'], true);
      if (is_array($j)) {
        $p['CZK'] = (int)($j['CZK'] ?? 0);
        $p['EUR'] = (int)($j['EUR'] ?? 0);
      }
    } catch (Throwable $e) {}
  }
  return $p;
}
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>Vstupenky (bez místa) — <?= h($event['title']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="/admin/assets/admin.css" rel="stylesheet">
  <?php $admin_event_title = $event['title'] ?? '';
$admin_back_href   = '/admin/event_detail.php?id=' . rawurlencode((string)($event['id'] ?? $id));
$admin_show_back   = true;

include __DIR__.'/_header.php';?>
<style>
  :root{
    --bg:#f6f7fb; --card:#fff; --border:#e5e7eb; --muted:#667085; --text:#0b1220;
    --brand:#2563eb; --brand-ink:#1e40af; --danger:#ef4444; --ok:#10b981; --warn:#f59e0b;
    --radius:14px; --radius-sm:10px; --gap:14px;
    --btn-h:36px; --btn-pad-x:12px; --shadow:0 6px 22px rgba(10,20,40,.05);
  }
  * { box-sizing: border-box }
  body{font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin:0; background:var(--bg); color:var(--text)}
  .wrap{max-width:1100px;margin:0 auto;padding:22px}
  .breadcrumbs{font-size:13px;color:var(--muted);margin-bottom:8px}
  .h1{font-size:26px;margin:6px 0 16px;font-weight:750;letter-spacing:-.015em}

  .card{position:relative; overflow:hidden;background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px}
  .card + .card{margin-top:16px}
  /* Kompaktní vstupy, aby se vše vešlo bez scrollu */
.input-sm{min-height:34px;padding:7px 10px;font-size:13px}
.color-sm{width:40px;height:34px;border-radius:8px}

/* Seznam existujících typů jako gridové karty */
.types-list{display:flex;flex-direction:column;gap:10px}
.type-card{
  border:1px solid var(--border); border-radius:var(--radius);
  background:#fff; box-shadow:var(--shadow); padding:12px;
}
.type-card .form-grid{gap:12px}
.type-card .field label{font-size:11px}

/* Sloupce stejné jako u "Přidat typ" */
.type-card .col-code{grid-column: span 2}
.type-card .col-name{grid-column: span 4}
.type-card .col-czk{ grid-column: span 2}
.type-card .col-eur{ grid-column: span 2}
.type-card .col-color{grid-column: span 1}
.type-card .col-cap{ grid-column: span 1}
.type-card .col-sold{grid-column: span 1}
.type-card .col-left{grid-column: span 1}
.type-card .col-actions{grid-column: 1 / -1; display:flex; gap:8px; justify-content:flex-end}

/* Čitelné čísla v chipu */
.chip-num{display:inline-flex;gap:6px;align-items:center;padding:6px 10px;border:1px solid var(--border);border-radius:999px;font-size:12px}
.chip-num strong{font-weight:700}

/* Responsivita */
@media (max-width: 1100px){
  .type-card .col-code{grid-column: span 2}
  .type-card .col-name{grid-column: span 5}
  .type-card .col-czk{ grid-column: span 2}
  .type-card .col-eur{ grid-column: span 2}
  .type-card .col-color{grid-column: span 1}
  .type-card .col-cap{ grid-column: span 2}
  .type-card .col-sold{grid-column: span 2}
  .type-card .col-left{grid-column: span 2}
}
@media (max-width: 760px){
  .type-card .col-code,
  .type-card .col-name,
  .type-card .col-czk,
  .type-card .col-eur,
  .type-card .col-color,
  .type-card .col-cap,
  .type-card .col-sold,
  .type-card .col-left,
  .type-card .col-actions{grid-column: 1 / -1}
}

.table-wrap{
  overflow-x: auto;
  overflow-y: visible;
  /* trocha „vzduchu“ pro box-shadow řádků, ať se neuseknou */
  margin: 0 -8px;
  padding: 0 8px 4px;
  -webkit-overflow-scrolling: touch;
}
  /* Buttons */
  .actions{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .btn{display:inline-flex;align-items:center;gap:8px;height:var(--btn-h);padding:0 var(--btn-pad-x);border:1px solid var(--border);border-radius:var(--radius-sm);background:#fff;color:var(--text);text-decoration:none;font-weight:650;font-size:14px;line-height:1;transition:.15s ease}
  .btn:hover{transform:translateY(-1px)}
  .btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}
  .btn.primary:hover{background:var(--brand-ink);border-color:var(--brand-ink)}
  .btn.danger{border-color:var(--danger);color:var(--danger);background:#fff}
  .btn.ghost{background:#f8fafc;border-color:var(--border);color:var(--text)}
  .btn.sm{height:32px;padding:0 10px;font-size:13px}

  /* Form grid */
  .form-grid{display:grid;grid-template-columns: repeat(12, 1fr);gap: var(--gap)}
  .field{display:flex;flex-direction:column;gap:6px}
  .field label{font-size:12px;color:var(--muted);font-weight:600}
  .field input[type="text"],
  .field input[type="number"]{width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:10px;min-height:38px;transition:border-color .12s, box-shadow .12s}
  .field input[type="text"]:focus,
  .field input[type="number"]:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
  .field input[type="color"]{width:48px;height:38px;padding:6px;border:1px solid var(--border);border-radius:10px}

  .col-code{grid-column: span 2}
  .col-name{grid-column: span 4}
  .col-czk{ grid-column: span 2}
  .col-eur{ grid-column: span 2}
  .col-color{grid-column: span 1}
  .col-cap{ grid-column: span 1}
  .col-actions{grid-column: 1 / -1; display:flex; justify-content:flex-end}

  @media (max-width: 900px){
    .col-code,.col-name,.col-czk,.col-eur,.col-color,.col-cap{grid-column: span 6}
  }
  @media (max-width: 560px){
    .col-code,.col-name,.col-czk,.col-eur,.col-color,.col-cap{grid-column: 1 / -1}
  }
  @media (max-width: 900px){
  .table{ min-width: 760px; }
  .table tbody td:last-child{ min-width: 160px; }
}

  /* Table → card-like rows with hover */
 .table{
  width: 100%;
  min-width: 940px;          /* klidně uprav podle počtu sloupců */
  border-collapse: separate;
  border-spacing: 0 8px;     /* jen vertikální mezera mezi „kartičkami“ */
}.table thead th,
.table tbody td{
  white-space: nowrap;        /* vstupy držme v jednom řádku */
}
.table tbody td:nth-child(2) input[type="text"]{
  min-width: 220px;           /* název – ať má trochu prostoru */
}

.table tbody td:last-child{   /* progress + akce */
  min-width: 200px;
}
  .table thead th{font-size:12px;color:var(--muted);font-weight:700;text-align:left;padding:0 10px}
  .table tbody tr{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 2px 10px rgba(10,20,40,.04)}
  .table tbody td{padding:10px;vertical-align:middle}
  .table input[type="text"], .table input[type="number"], .table input[type="color"]{min-height:34px}
  .table tbody tr:hover{box-shadow:0 6px 18px rgba(10,20,40,.07)}
  .muted{color:#6b7280}

  /* Swatch + chip */
  .swatch{display:inline-flex;align-items:center;gap:8px}
  .swatch-dot{width:18px;height:18px;border-radius:8px;border:1px solid rgba(0,0,0,.08)}
  .chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--border);border-radius:999px;font-size:12px}

  /* Progress (Sold vs Capacity) */
  .progress{display:flex;align-items:center;gap:8px}
  .bar{flex:1;height:8px;background:#f1f5f9;border-radius:999px;overflow:hidden}
  .bar > span{display:block;height:100%;width:0}
  .bar .sold{background:var(--brand)}
  .bar .warn{background:var(--warn)}
  .bar .ok{background:var(--ok)}
  .qty{min-width:72px;text-align:right;font-variant-numeric:tabular-nums}

  /* Flash */
  .flash{margin:10px 0}
  .flash .ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px}
  .flash .err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px;border-radius:10px}

  /* Sticky utility bar (optional) */
  .actions.sticky{position:sticky;top:0;background:linear-gradient(#f6f7fb,#f6f7fbcc);backdrop-filter:saturate(1.1) blur(2px);z-index:10;padding:10px 0 12px;margin:-10px 0 12px;border-bottom:1px solid var(--border)}
</style>


</head>
<body>
  <h1 class="h1">Vstupenky — <?= h($event['title']) ?></h1>
  <?php if ($flash['ok']): ?>
    <div class="flash"><div class="ok"><?= h($flash['ok']) ?></div></div>
  <?php endif; ?>
  <?php if ($flash['err']): ?>
    <div class="flash"><div class="err"><?= h($flash['err']) ?></div></div>
  <?php endif; ?>

  <!-- Přidat nový typ -->
<div class="card" style="margin-bottom:16px">
  <h3 style="margin:0 0 12px">Přidat typ vstupenky</h3>
  <form method="post" class="form-grid">
    <input type="hidden" name="action" value="create">

    <div class="field col-code">
      <label>Kód</label>
      <input type="text" name="code" maxlength="16" required>
    </div>

    <div class="field col-name">
      <label>Název</label>
      <input type="text" name="name" maxlength="100" required>
    </div>

    <div class="field col-czk">
      <label>Cena CZK</label>
      <input type="number" name="price_czk" min="0" step="1" value="0">
    </div>

    <div class="field col-eur">
      <label>Cena EUR</label>
      <input type="number" name="price_eur" min="0" step="1" value="0">
    </div>

    <div class="field col-color">
      <label>Barva</label>
      <input type="color" name="color" value="#2563eb">
    </div>

    <div class="field col-cap">
      <label>Kapacita</label>
      <input type="number" name="capacity" min="0" step="1" value="0">
    </div>

    <div class="col-actions">
      <button class="btn primary sm" type="submit">
        <i class="fa-regular fa-floppy-disk"></i> Přidat
      </button>
    </div>
  </form>
</div>
  <!-- Seznam / editace typů -->
  <div class="card">
    <h3 style="margin:0 0 12px">Existující typy</h3>
    <?php if (!$types): ?>
      <div class="muted">Zatím žádné typy.</div>
    <?php else: ?>
       <div class="types-list">
  <?php foreach ($types as $row):
    $p = prices($row);
    $remaining = max(0, (int)$row['capacity'] - (int)$row['sold']);
  ?>
  <div class="type-card">
    <form method="post" class="form-grid" action="">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">

      <div class="field col-code">
        <label>Kód</label>
        <input class="input-sm" type="text" name="code" value="<?= h($row['code']) ?>" maxlength="16" required>
      </div>

      <div class="field col-name">
        <label>Název</label>
        <input class="input-sm" type="text" name="name" value="<?= h($row['name']) ?>" maxlength="100" required>
      </div>

      <div class="field col-czk">
        <label>Cena CZK</label>
        <input class="input-sm" type="number" name="price_czk" value="<?= (int)$p['CZK'] ?>" min="0" step="1">
      </div>

      <div class="field col-eur">
        <label>Cena EUR</label>
        <input class="input-sm" type="number" name="price_eur" value="<?= (int)$p['EUR'] ?>" min="0" step="1">
      </div>

      <div class="field col-color">
        <label>Barva</label>
        <input class="color-sm" type="color" name="color" value="<?= h($row['color'] ?: '#2563eb') ?>">
      </div>

      <div class="field col-cap">
        <label>Kapacita</label>
        <input class="input-sm js-cap" type="number" name="capacity" value="<?= (int)$row['capacity'] ?>" min="0" step="1">
      </div>

      <div class="field col-sold">
        <label>Prodáno</label>
        <div class="chip-num"><strong class="js-sold"><?= (int)$row['sold'] ?></strong> ks</div>
      </div>

      <div class="field col-left">
        <label>Zbývá</label>
        <div class="chip-num"><strong class="js-left"><?= (int)$remaining ?></strong> ks</div>
      </div>

      <div class="col-actions">
        <button type="submit" class="btn primary sm">Uložit</button>
    </form>
        <form method="post" onsubmit="return confirm('Opravdu smazat tento typ?');">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="btn danger sm">Smazat</button>
        </form>
      </div>
  </div>
  <?php endforeach; ?>
</div>
    <?php endif; ?>
  </div>
</div>
<script>
document.querySelectorAll('.type-card').forEach(function(card){
  const cap = card.querySelector('.js-cap');
  const sold = card.querySelector('.js-sold');
  const left = card.querySelector('.js-left');
  if (!cap || !sold || !left) return;

  function recalc(){
    const c = Math.max(0, parseInt(cap.value||'0',10));
    const s = Math.max(0, parseInt(sold.textContent||'0',10));
    left.textContent = Math.max(0, c - s);
    if (s > c) { cap.setCustomValidity('Kapacita je menší než prodané množství.'); cap.reportValidity(); }
    else { cap.setCustomValidity(''); }
  }
  cap.addEventListener('input', recalc);
  recalc();
});
</script>


</body>
</html>
