<?php
declare(strict_types=1);
ob_start();
// admin/event_edit.php — hezky nastylované UI + AJAX upload/mazání obrázků
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';

ensure_admin();
// ===== DUPLICATE — MUSÍ BÝT DŘÍV NEŽ SAVE HANDLER =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['__action'] ?? '') === 'duplicate')) {
  try {
    $srcId = $_POST['id'] ?? '';
    if (!$srcId) {
      header('Location: /admin/event_edit.php?cloned=0', true, 303);
      exit;
    }

    $q = db()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
    $q->execute([$srcId]);
    $src = $q->fetch(PDO::FETCH_ASSOC);
    if (!$src) {
      header('Location: /admin/event_edit.php?cloned=0', true, 303);
      exit;
    }

    // nový ID + title/slug
    $newId = uuidv4();
    $newTitle = trim(($src['title'] ?? 'akce') . ' (kopie)');

    $base = slugify($src['slug'] ?: ($src['title'] ?? 'akce'));
    $newSlug = $base . '-copy';
    $chk = db()->prepare("SELECT 1 FROM events WHERE slug=?");
    $i = 1;
    while (true) {
      $chk->execute([$newSlug]);
      if (!$chk->fetchColumn()) break;
      $i++;
      $newSlug = $base . '-copy-' . $i;
    }

    // POZOR: 18 sloupců => 18 placeholderů
    $ins = db()->prepare("
      INSERT INTO events
        (id,title,slug,description,venue_name,address,map_embed_url,starts_at,ends_at,timezone,status,selling_mode,
         organizer_name,organizer_email,organizer_phone,organizer_website,organizer_facebook,cover_image_url)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([
      $newId,
      $newTitle,
      $newSlug,
      $src['description'] ?? '',
      $src['venue_name'] ?? '',
      $src['address'] ?? '',
      $src['map_embed_url'] ?? '',
      $src['starts_at'] ?? null,
      $src['ends_at'] ?? null,
      $src['timezone'] ?? 'Europe/Prague',
      'draft',
      $src['selling_mode'] ?? 'mixed',
      $src['organizer_name'] ?? '',
      $src['organizer_email'] ?? '',
      $src['organizer_phone'] ?? '',
      $src['organizer_website'] ?? '',
      $src['organizer_facebook'] ?? '',
      $src['cover_image_url'] ?? null
    ]);

    // galerie
    $imgs = db()->prepare("SELECT url,is_cover,sort_order FROM event_images WHERE event_id=? ORDER BY sort_order,id");
    $imgs->execute([$srcId]);
    $insImg = db()->prepare("INSERT INTO event_images(event_id,url,is_cover,sort_order) VALUES (?,?,?,?)");
    foreach ($imgs->fetchAll(PDO::FETCH_ASSOC) as $im) {
      $insImg->execute([$newId, $im['url'], (int)$im['is_cover'], (int)$im['sort_order']]);
    }

    header('Location: /admin/event_edit.php?id=' . rawurlencode((string)$newId) . '&cloned=1', true, 303);
    exit;

  } catch (Throwable $e) {
    error_log('Duplicate error: '.$e->getMessage());
    header('Location: /admin/event_edit.php?cloned=0&err=1', true, 303);
    exit;
  }
}


// ===== ID akce (rozhoduje i pro "Zpět" tlačítko) =====
$id = $_GET['id'] ?? $_POST['id'] ?? '';

// ===== DEBUG (volitelné) =====
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ===== Pomocné =====
function uuidv4() {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
function json_out($arr, $code=200){
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}
function safe_include_footer(): bool {
  $path = __DIR__.'/_footer.html';
  if (file_exists($path)) { require $path; return true; }
  return false;
}

// ===== Načti event (nebo připrav nové ID) =====
$event = null;
if ($id) {
  $stmt = db()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $event = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} else {
  // nový – předvygenerujeme ID, ať můžeme nahrávat soubory
  $id = uuidv4();
}
// ===== AJAX end-pointy (upload/mazání) =====
if (isset($_GET['ajax'])) {
  $ajax = $_GET['ajax'];

  if ($ajax === 'upload_cover') {
    try {
      if (empty($_FILES['file']['name'])) json_out(['ok'=>false,'msg'=>'Chybí soubor'], 400);

      // zajisti, že akce existuje (kvůli uložení coveru)
      $exists = db()->prepare("SELECT 1 FROM events WHERE id=?");
      $exists->execute([$id]);
      if (!$exists->fetchColumn()) {
        $title='Akce'; $slug=slugify($title);
        $ins = db()->prepare("INSERT INTO events (id,title,slug,status,timezone) VALUES (?,?,?,?,?)");
        $ins->execute([$id,$title,$slug,'draft','Europe/Prague']);
      }

      $url = save_uploaded_image($_FILES['file']);
      if (!$url) json_out(['ok'=>false,'msg'=>'Uložení obrázku selhalo'], 500);

      // ⚠️ pouze update cover_image_url (žádné nedefinované proměnné)
      $up = db()->prepare("UPDATE events SET cover_image_url=? WHERE id=?");
      $up->execute([$url, $id]);

      json_out(['ok'=>true, 'url'=>$url, 'event_id'=>$id]);
    } catch(Throwable $e) {
      json_out(['ok'=>false,'msg'=>$e->getMessage()],500);
    }
  }

  if ($ajax === 'upload_gallery') {
    try{
      if (empty($_FILES['file']['name'])) json_out(['ok'=>false,'msg'=>'Chybí soubor'], 400);

      $exists = db()->prepare("SELECT 1 FROM events WHERE id=?");
      $exists->execute([$id]);
      if (!$exists->fetchColumn()) {
        $title='Akce'; $slug=slugify($title);
        $ins = db()->prepare("INSERT INTO events (id,title,slug,status,timezone) VALUES (?,?,?,?,?)");
        $ins->execute([$id,$title,$slug,'draft','Europe/Prague']);
      }

      $url = save_uploaded_image($_FILES['file']);
      if (!$url) json_out(['ok'=>false,'msg'=>'Uložení obrázku selhalo'], 500);

      $q = db()->prepare("SELECT COALESCE(MAX(sort_order), -1) AS m FROM event_images WHERE event_id=?");
      $q->execute([$id]);
      $m = (int)($q->fetch(PDO::FETCH_ASSOC)['m'] ?? -1);

      $ins = db()->prepare("INSERT INTO event_images(event_id, url, is_cover, sort_order) VALUES (?, ?, 0, ?)");
      $ins->execute([$id, $url, $m+1]);

      $imId = (int)db()->lastInsertId();
      json_out(['ok'=>true, 'url'=>$url, 'image_id'=>$imId, 'event_id'=>$id]);
    } catch(Throwable $e){
      json_out(['ok'=>false,'msg'=>$e->getMessage()],500);
    }
  }

  if ($ajax === 'delete_image') {
    try{
      $imgId = (int)($_POST['image_id'] ?? 0);
      if ($imgId<=0) json_out(['ok'=>false,'msg'=>'Neplatné ID'], 400);

      $chk = db()->prepare("SELECT id FROM event_images WHERE id=? AND event_id=? LIMIT 1");
      $chk->execute([$imgId, $id]);
      if (!$chk->fetch()) json_out(['ok'=>false,'msg'=>'Obrázek nenalezen'], 404);

      $del = db()->prepare("DELETE FROM event_images WHERE id=? LIMIT 1");
      $del->execute([$imgId]);

      json_out(['ok'=>true]);
    } catch(Throwable $e){
      json_out(['ok'=>false,'msg'=>$e->getMessage()],500);
    }
  }

  json_out(['ok'=>false,'msg'=>'Neznámá akce'], 400);
}
/* >>> TADY (AŽ TEĎ) dej proměnné pro header a include headeru <<< */
$admin_event_title = $event['title'] ?? '';
$admin_back_href   = '/admin/event_detail.php?id=' . rawurlencode((string)($event['id'] ?? $id));
$admin_show_back   = true;

include __DIR__.'/_header.php';
// ===== Uložení formuláře (POST) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST'   && empty($_GET['ajax'])
  && (($_POST['__action'] ?? '') !== 'duplicate')
) {
  $title = trim($_POST['title'] ?? 'Akce');
  $slug  = trim($_POST['slug'] ?? '');
  if (!$slug) $slug = slugify($title);

  $desc   = $_POST['description'] ?? '';
  $venue  = trim($_POST['venue_name'] ?? '');
  $address= trim($_POST['address'] ?? '');
  $map_embed_url = trim($_POST['map_embed_url'] ?? '');
  $starts = $_POST['starts_at'] ?? '';
  $ends   = $_POST['ends_at'] ?? null;

  $tz     = $event['timezone'] ?? 'Europe/Prague'; // držíme aktuální, z UI teď neposíláš
  $status = $_POST['status'] ?? 'draft';
  if (in_array($status, ['cancelled','canceled','zruseno','zrušeno'], true)) {
    $status = 'archived';
  }
  

  $mode = $_POST['selling_mode'] ?? ($event['selling_mode'] ?? 'mixed');
  if (!in_array($mode, ['seats','ga','mixed'], true)) { $mode = 'mixed'; }
  $purge = !empty($_POST['purge_seatmap']);

  $org_name = trim($_POST['organizer_name'] ?? '');
  $org_email= trim($_POST['organizer_email'] ?? '');
  $org_phone= trim($_POST['organizer_phone'] ?? '');
  $org_web  = trim($_POST['organizer_website'] ?? '');
  $org_fb   = trim($_POST['organizer_facebook'] ?? '');

  // Upsert
  $exists = db()->prepare("SELECT 1 FROM events WHERE id=?");
  $exists->execute([$id]);

  if ($exists->fetchColumn()) {
    $stmt = db()->prepare("UPDATE events
      SET title=?, slug=?, description=?, venue_name=?, address=?, map_embed_url=?,
          starts_at=?, ends_at=?, timezone=?, status=?, selling_mode=?,
          organizer_name=?, organizer_email=?, organizer_phone=?, organizer_website=?, organizer_facebook=?
      WHERE id=?");
    $stmt->execute([
      $title,$slug,$desc,$venue,$address,$map_embed_url,
      $starts,$ends,$tz,$status,$mode,
      $org_name,$org_email,$org_phone,$org_web,$org_fb,
      $id
    ]);
    
  } else {
    $stmt = db()->prepare("INSERT INTO events
      (id,title,slug,description,venue_name,address,map_embed_url,starts_at,ends_at,timezone,status,selling_mode,organizer_name,organizer_email,organizer_phone,organizer_website,organizer_facebook)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
      $id,$title,$slug,$desc,$venue,$address,$map_embed_url,$starts,$ends,$tz,$status,$mode,$org_name,$org_email,$org_phone,$org_web,$org_fb
    ]);
  }

  if ($mode === 'ga' && $purge) {
    $q = db()->prepare("
      UPDATE event_seatmaps
      SET schema_json = JSON_SET(COALESCE(schema_json, '{}'),
        '$.seats',  JSON_ARRAY(),
        '$.tables', JSON_ARRAY(),
        '$.tiers',  JSON_OBJECT(),
        '$.rows',   JSON_OBJECT()
      )
      WHERE event_id=?
    ");
    $q->execute([$id]);
  }

$redir = '/admin/event_edit.php?id=' . rawurlencode((string)$id) . '&saved=1';
header('Location: ' . $redir, true, 303);
exit;
}
// ===== Duplikace akce (POST __action=duplicate) =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['__action'] ?? '') === 'duplicate') {
  $srcId = $_POST['id'] ?? '';
  if (!$srcId) {
    header('Location: /admin/event_edit.php?s=notfound', true, 303);
    exit;
  }

  $q = db()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
  $q->execute([$srcId]);
  $src = $q->fetch(PDO::FETCH_ASSOC);
  if (!$src) {
    header('Location: /admin/event_edit.php?s=notfound', true, 303);
    exit;
  }

  // nový ID + title/slug
  $newId = uuidv4();
  $newTitle = trim(($src['title'] ?? 'Akce') . ' (kopie)');

  // base slug z původního slugu nebo title
  $base = slugify($src['slug'] ?: $src['title'] ?: 'Akce');
  $newSlug = $base . '-copy';
  $chk = db()->prepare("SELECT 1 FROM events WHERE slug=?");
  $i = 1;
  while (true) {
    $chk->execute([$newSlug]);
    if (!$chk->fetchColumn()) break;
    $i++;
    $newSlug = $base . '-copy-' . $i;
  }

  // vlož kopii (status vždy 'draft')
  $ins = db()->prepare("
    INSERT INTO events
      (id,title,slug,description,venue_name,address,map_embed_url,starts_at,ends_at,timezone,status,selling_mode,
       organizer_name,organizer_email,organizer_phone,organizer_website,organizer_facebook)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  $ins->execute([
    $newId,
    $newTitle,
    $newSlug,
    $src['description'] ?? '',
    $src['venue_name'] ?? '',
    $src['address'] ?? '',
    $src['map_embed_url'] ?? '',
    $src['starts_at'] ?? null,
    $src['ends_at'] ?? null,
    $src['timezone'] ?? 'Europe/Prague',
    'draft',
    $src['selling_mode'] ?? 'mixed',
    $src['organizer_name'] ?? '',
    $src['organizer_email'] ?? '',
    $src['organizer_phone'] ?? '',
    $src['organizer_website'] ?? '',
    $src['organizer_facebook'] ?? ''
  ]);

  // zkopíruj galerii
  $imgs = db()->prepare("SELECT url,is_cover,sort_order FROM event_images WHERE event_id=? ORDER BY sort_order,id");
  $imgs->execute([$srcId]);
  $insImg = db()->prepare("INSERT INTO event_images(event_id,url,is_cover,sort_order) VALUES (?,?,?,?)");
  foreach ($imgs->fetchAll(PDO::FETCH_ASSOC) as $im) {
    $insImg->execute([$newId, $im['url'], (int)$im['is_cover'], (int)$im['sort_order']]);
  }

  header('Location: /admin/event_edit.php?id=' . rawurlencode((string)$newId) . '&cloned=1', true, 303);
  exit;
}

// ===== Znovu načti data akce + obrázky =====
$stmt = db()->prepare("SELECT * FROM events WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$event = $stmt->fetch(PDO::FETCH_ASSOC) ?: ($event ?: ['id'=>$id, 'title'=>'', 'status'=>'draft', 'timezone'=>'Europe/Prague']);

$q = db()->prepare("SELECT * FROM event_images WHERE event_id=? ORDER BY sort_order, id");
$q->execute([$id]);
$imgs = $q->fetchAll(PDO::FETCH_ASSOC);

// ===== STYL A OBSAH — pouze CONTENT (žádný další <html> / <head> / <body>) =====
?>
<style>
/* === Scoping: vše pod .edit-page, aby se to nebilo s ostatními styly === */ Zkouším zda se to přenese
.edit-page{
  --bg:#f7f8fb; --panel:#fff; --text:#0b1220; --muted:#5b677a; --border:#e6e9ef;
  --accent:#2563eb; --accent-600:#1d4ed8; --radius:14px; --shadow:0 8px 24px rgba(3,14,38,.06);
  --chip-bg:#f6f8ff; --chip-br:#dbe4ff;
}
.edit-page *{ box-sizing:border-box }
.edit-page a{ color:var(--accent); text-decoration:none }
.edit-page a:hover{ text-decoration:underline }
.edit-page .wrap{ max-width:1100px; margin:0 auto; padding:20px 16px; color:var(--text) }
/* flash/toast */
.edit-page .flash {
  position: sticky; top: 10px; z-index: 60;
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border-radius: 12px;
  border: 1px solid #c7f3d5; background: #ecfdf5; color: #065f46;
  box-shadow: 0 6px 20px rgba(3,14,38,.06);
  margin-bottom: 12px;
}
.edit-page .flash.error {
  border-color:#fbd5d5; background:#fef2f2; color:#7f1d1d;
}
.edit-page .flash .x { margin-left:auto; cursor:pointer; border:0; background:transparent; font-size:16px; line-height:1; }

/* Titulek + toolbar nahoře */
.edit-page .topbar{
  display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:14px;
}
.edit-page .title{
  display:flex; align-items:center; gap:10px; font-size:22px; font-weight:800; letter-spacing:.2px;
}
.edit-page .title .chip{
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; border-radius:999px; font-weight:700; font-size:12px;
  color:#0b4aa6; background:var(--chip-bg); border:1px solid var(--chip-br);
}

/* 2 sloupce */
.edit-page .grid{
  display:grid; grid-template-columns: 1.45fr .9fr; gap:16px; align-items:start;
}
@media (max-width:1020px){ .edit-page .grid{ grid-template-columns:1fr } }

/* karty */
.edit-page .card{
  background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
  padding:14px; box-shadow:var(--shadow);
}
.edit-page .card + .card{ margin-top:12px }
.edit-page .k-head{ display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:8px }
.edit-page .k-head .h{
  display:flex; align-items:center; gap:8px; font-weight:800; font-size:15px;
}
.edit-page .k-head .h i{ color:var(--accent) }

/* formuláře */
.edit-page label{ display:flex; flex-direction:column; gap:6px; font-weight:560; color:#334155; margin:6px 0 }
.edit-page input[type="text"],
.edit-page input[type="email"],
.edit-page input[type="url"],
.edit-page input[type="datetime-local"],
.edit-page select,
.edit-page textarea{
  width:100%; padding:10px 11px; border:1px solid #d1d5db; border-radius:10px; background:#fff; font-size:14px;
}
.edit-page textarea{ resize:vertical; min-height:100px }
.edit-page input:focus, .edit-page textarea:focus, .edit-page select:focus{
  outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.14);
}

/* vnitřní mřížky */
.edit-page .g2{ display:grid; grid-template-columns:1fr 1fr; gap:12px }
.edit-page .g3{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px }
@media (max-width:720px){ .edit-page .g2, .edit-page .g3{ grid-template-columns:1fr } }

/* uploader + galerie */
.edit-page .uploader{
  border:2px dashed var(--border); border-radius:12px; background:#fbfcff; padding:14px; text-align:center;
}
.edit-page .uploader.drag{ background:#f0f7ff; border-color:#bfdbfe }
.edit-page .uploader input[type=file]{ display:none }
.edit-page .uploader .hint{ color:#42536a; font-size:14px }
.edit-page .uploader .sub{ font-size:12px; color:#7b8699; margin-top:4px }
.edit-page .cover-row{ display:grid; grid-template-columns: 240px 1fr; gap:14px; align-items:center }
@media (max-width:900px){ .edit-page .cover-row{ grid-template-columns:1fr } }
.edit-page .cover-preview{ border:1px solid var(--border); border-radius:12px; overflow:hidden; background:#f3f6fb }
.edit-page .cover-preview img{ display:block; width:100%; height:auto }
.edit-page .gallery{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px }
@media (max-width:1024px){ .edit-page .gallery{ grid-template-columns:repeat(3,1fr) } }
@media (max-width:760px){ .edit-page .gallery{ grid-template-columns:repeat(2,1fr) } }
.edit-page .thumb{ position:relative; border:1px solid var(--border); border-radius:10px; overflow:hidden; background:#f3f6fb }
.edit-page .thumb img{ width:100%; aspect-ratio:4/3; object-fit:cover; display:block }
.edit-page .thumb .del{
  position:absolute; top:6px; right:6px; background:rgba(0,0,0,.55); color:#fff;
  border:none; border-radius:999px; width:28px; height:28px; font-weight:800; cursor:pointer
}

/* sticky sidebar */
.edit-page .sticky{ position:sticky; top:76px }

/* tlačítka */
.edit-page .actions{ display:flex; gap:8px; align-items:center; flex-wrap:wrap }
.edit-page .btn{
  display:inline-flex; align-items:center; gap:8px; padding:10px 12px; border-radius:10px;
  border:1px solid var(--accent); background:var(--accent); color:#fff; font-weight:700; text-decoration:none; cursor:pointer;
}
.edit-page .btn i{ font-size:14px }
.edit-page .btn:hover{ background:var(--accent-600); border-color:var(--accent-600) }
.edit-page .btn.outline{ background:#fff; color:var(--accent) }
.edit-page .btn.outline:hover{ background:var(--accent); color:#fff }

/* drobné */
.edit-page .small{ font-size:12px; color:var(--muted) }
</style>

<div class="edit-page">
  <div class="wrap">
<?php if (!empty($_GET['saved'])): ?>
  <div class="flash" id="flash"><i class="fa-regular fa-circle-check"></i> Změny byly uloženy. <button class="x" onclick="this.parentElement.remove()" aria-label="Zavřít">×</button></div>
<?php elseif (!empty($_GET['cloned'])): ?>
  <div class="flash" id="flash"><i class="fa-regular fa-copy"></i> Akce byla duplikována. <button class="x" onclick="this.parentElement.remove()" aria-label="Zavřít">×</button></div>
<?php endif; ?>
    <?php
    // doplň „Zpět“ proměnné (můžou být prázdné u nové akce)
    $backHref = isset($event['id']) && $event['id'] !== ''
      ? '/admin/event_detail.php?id=' . rawurlencode((string)$event['id'])
      : '/admin/';
    $backText = isset($event['id']) && $event['id'] !== '' ? 'Zpět na detail' : 'Zpět na přehled';

    // pro štítek stavu
    $statusLabels = ['draft'=>'Koncept','on_sale'=>'V prodeji','sold_out'=>'Vyprodáno','archived'=>'Archiv'];
    $cur = $event['status'] ?? 'draft';
    if (in_array($cur, ['cancelled','canceled','zruseno','zrušeno'], true)) { $cur = 'archived'; }
    ?>

    <!-- JEDEN jediný FORM -->
    <form id="eventForm" method="post" enctype="multipart/form-data">
      <input type="hidden" name="id" id="eventId" value="<?= htmlspecialchars($id) ?>">

      <!-- horní lišta -->
      <div class="topbar">
        <div class="title">
          <span><?= ($event && !empty($event['title'])) ? 'Upravit akci' : 'Nová akce' ?></span>
          <span class="chip"><i class="fa-regular fa-flag"></i> <?= htmlspecialchars($statusLabels[$cur] ?? 'Neznámý') ?></span>
        </div>
        <div class="actions">
  <button type="submit" class="btn"><i class="fa-regular fa-floppy-disk"></i> Uložit</button>
   <a href="/e/<?= urlencode($event['slug'] ?? '') ?>" target="_blank" class="btn outline">
    <i class="fa-regular fa-eye"></i></a>
</div>

      </div>

      <div class="grid">
        <!-- LEVÝ SLOUPEC -->
        <div>
          <!-- Základní informace -->
          <div class="card">
            <div class="k-head"><div class="h"><i class="fa-regular fa-pen-to-square"></i> Základní informace</div></div>

            <label>Název akce
              <input type="text" name="title" value="<?= htmlspecialchars($event['title'] ?? '') ?>" required>
            </label>

            <div class="g2">
              <label><span><i class="fa-solid fa-link"></i> URL (slug)</span>
                <input type="text" name="slug" value="<?= htmlspecialchars($event['slug'] ?? '') ?>">
              </label>
              <label><span><i class="fa-regular fa-flag"></i> Status</span>
                <select name="status">
                  <?php foreach (['draft'=>'Koncept','on_sale'=>'V prodeji','sold_out'=>'Vyprodáno','archived'=>'Archivovat'] as $val=>$label): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $cur === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          </div>

          <!-- Datum a místo -->
          <div class="card">
            <div class="k-head"><div class="h"><i class="fa-regular fa-calendar"></i> Datum a místo</div></div>

            <div class="g2">
              <label>Od
                <input type="datetime-local" name="starts_at" value="<?= isset($event['starts_at']) ? str_replace(' ','T', substr($event['starts_at'],0,16)) : '' ?>" required>
              </label>
              <label>Do
                <input type="datetime-local" name="ends_at" value="<?= isset($event['ends_at']) ? str_replace(' ','T', substr($event['ends_at'],0,16)) : '' ?>">
              </label>
            </div>

            <label><span><i class="fa-regular fa-map"></i> Adresa</span>
              <input type="text" name="address" value="<?= htmlspecialchars($event['address'] ?? '') ?>">
            </label>

            <label><span><i class="fa-solid fa-map-pin"></i> Mapa</span>
              <textarea name="map_embed_url" rows="3"><?= htmlspecialchars($event['map_embed_url'] ?? '') ?></textarea>
              <div class="small">Tip: Google Maps → Sdílet → Vložit mapu → zkopírujte <code>src</code> nebo celý &lt;iframe&gt;.</div>
            </label>
          </div>

          <!-- Popis -->
          <div class="card">
            <div class="k-head"><div class="h"><i class="fa-regular fa-file-lines"></i> Popis</div></div>
            <textarea id="desc-editor" name="description" rows="12"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
          </div>

          <!-- Pořadatel -->
          <div class="card">
            <div class="k-head"><div class="h"><i class="fa-regular fa-building"></i> Pořadatel</div></div>

            <div class="g2">
              <label>Název organizátora
                <input type="text" name="organizer_name" value="<?= htmlspecialchars($event['organizer_name'] ?? '') ?>">
              </label>
              <label>Oficiální web
                <input type="url" name="organizer_website" value="<?= htmlspecialchars($event['organizer_website'] ?? '') ?>">
              </label>
            </div>

            <div class="g3">
              <label>E-mail
                <input type="email" name="organizer_email" value="<?= htmlspecialchars($event['organizer_email'] ?? '') ?>">
              </label>
              <label>Telefon
                <input type="text" name="organizer_phone" value="<?= htmlspecialchars($event['organizer_phone'] ?? '') ?>">
              </label>
              <label>Facebook
                <input type="url" name="organizer_facebook" value="<?= htmlspecialchars($event['organizer_facebook'] ?? '') ?>">
              </label>
            </div>
          </div>

          <!-- Spodní akce -->
          <div class="actions" style="margin:6px 0 24px">
            <button type="submit" class="btn"><i class="fa-regular fa-floppy-disk"></i> Uložit změny</button>
          </div>
        </div>

        <!-- PRAVÝ SLOUPEC -->
        <aside class="sticky">

          <!-- Prodej -->
          <div class="card">
            <div class="k-head"><div class="h"><i class="fa-solid fa-ticket"></i> Prodej</div></div>
            <?php $m = $event['selling_mode'] ?? 'mixed'; ?>
            <label>Režim prodeje
              <select name="selling_mode">
                <option value="seats" <?= $m==='seats'?'selected':'' ?>>Sedadla</option>
                <option value="ga"    <?= $m==='ga'?'selected':''    ?>>Volné vstupenky</option>
                <option value="mixed" <?= $m==='mixed'?'selected':'' ?>>Sedadla + Vstupenky</option>
              </select>
            </label>
            </div>

          <!-- Cover -->
          <div class="card">
            <div class="k-head"><div class="h"><i class="fa-regular fa-image"></i> Náhledový obrázek</div></div>
            <div class="cover-row">
              <div class="cover-preview" id="coverPreview">
                <?php if (!empty($event['cover_image_url'])): ?>
                  <img src="<?= htmlspecialchars($event['cover_image_url']) ?>" alt="cover">
                <?php else: ?>
                  <div style="display:flex;align-items:center;justify-content:center;aspect-ratio:4/3;color:#6b7280">Bez náhledu</div>
                <?php endif; ?>
              </div>
              <div>
                <div class="uploader" id="coverUp">
                  <input type="file" id="coverInput" accept="image/*">
                  <div class="hint"><strong>Obrázek přetáhněte</strong> nebo klikněte pro výběr</div>
                  <div class="sub">Po nahrání se uloží automaticky.</div>
                </div>
                <div class="small" id="coverMsg" style="margin-top:6px"></div>
              </div>
            </div>
          </div>

          <!-- Galerie -->
          <div class="card">
            <div class="k-head">
              <div class="h"><i class="fa-regular fa-images"></i> Galerie</div>
               <!-- skrytý input pro klik přidání -->
 <input type="file" id="galInput" accept="image/*" multiple style="display:none">
    <label class="btn outline" for="galInput" style="margin:0">
      <i class="fa-solid fa-plus"></i> Přidat
    </label>
            </div>

            <div class="uploader" id="galDrop" style="margin-bottom:10px">
              <div class="hint"><strong>Obrázky přetáhněte</strong> nebo klikněte na +přidat</div>
              <div class="sub">Uloží se automaticky</div>
            </div>

            <div class="gallery" id="galleryList">
              <?php foreach ($imgs as $im): ?>
                <div class="thumb" data-id="<?= (int)$im['id'] ?>">
                  <img src="<?= htmlspecialchars($im['url']) ?>" alt="">
                  <button type="button" class="del" title="Smazat" aria-label="Smazat">×</button>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="small" id="galMsg" style="margin-top:6px"></div>
          </div>

        </aside>
      </div><!-- /grid -->

    </form>
<form id="dupForm" method="post" style="display:none">
  <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
  <input type="hidden" name="__action" value="duplicate">
</form>

  </div><!-- /.wrap -->
</div><!-- /.edit-page -->

<script>
// (JS nechávám beze změn)
const eventId = document.getElementById('eventId').value;
function $(sel, root=document){ return root.querySelector(sel); }
function el(tag, cls){ const e=document.createElement(tag); if(cls) e.className=cls; return e; }

(function(){
  const up = $('#coverUp'), inp = $('#coverInput'), prev= $('#coverPreview'), msg = $('#coverMsg');
  function setDrag(on){ up.classList.toggle('drag', !!on); }
  function handleFile(file){
    if(!file) return;
    const fd = new FormData(); fd.append('file', file);
    fetch(`?id=${encodeURIComponent(eventId)}&ajax=upload_cover`, { method:'POST', body: fd })
      .then(r=>r.json()).then(d=>{
        if(!d.ok) throw new Error(d.msg||'Upload selhal');
        msg.textContent = 'Cover uložen.'; prev.innerHTML = '';
        const img = new Image(); img.src = d.url; img.alt = 'cover'; img.onload=()=> prev.appendChild(img);
      }).catch(e=>{ msg.textContent = 'Chyba: '+e.message; });
  }
  up.addEventListener('click', ()=> inp.click());
  up.addEventListener('dragover', e=>{ e.preventDefault(); setDrag(true); });
  up.addEventListener('dragleave', e=>{ e.preventDefault(); setDrag(false); });
  up.addEventListener('drop', e=>{ e.preventDefault(); setDrag(false); handleFile(e.dataTransfer.files[0]); });
  inp.addEventListener('change', ()=> handleFile(inp.files[0]));
})();

(function(){
  const list=$('#galleryList'), drop=$('#galDrop'), input=$('#galInput'), msg=$('#galMsg');
  function addThumb(id,url){ const t=el('div','thumb'); t.dataset.id=id; const img=new Image(); img.src=url; t.appendChild(img);
    const del=el('button','del'); del.type='button'; del.textContent='×'; del.title='Smazat';
    del.addEventListener('click',()=>delImage(id,t)); t.appendChild(del); list.prepend(t); }
  function uploadFile(file){ const fd=new FormData(); fd.append('file',file);
    fetch(`?id=${encodeURIComponent(eventId)}&ajax=upload_gallery`,{method:'POST',body:fd})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw new Error(d.msg||'Upload selhal'); addThumb(d.image_id,d.url); msg.textContent='Obrázek přidán.'; })
      .catch(e=>{ msg.textContent='Chyba: '+e.message; }); }
  function delImage(imageId,node){
    if(!confirm('Opravdu smazat tento obrázek?')) return;
    const fd=new FormData(); fd.append('image_id',imageId);
    fetch(`?id=${encodeURIComponent(eventId)}&ajax=delete_image`,{method:'POST',body:fd})
      .then(r=>r.json()).then(d=>{ if(!d.ok) throw new Error(d.msg||'Mazání selhalo'); node.remove(); msg.textContent='Obrázek smazán.'; })
      .catch(e=>{ msg.textContent='Chyba: '+e.message; }); }
  drop.addEventListener('dragover',e=>{ e.preventDefault(); drop.classList.add('drag'); });
  drop.addEventListener('dragleave',e=>{ e.preventDefault(); drop.classList.remove('drag'); });
  drop.addEventListener('drop',e=>{ e.preventDefault(); drop.classList.remove('drag'); [...e.dataTransfer.files].forEach(uploadFile); });
  input.addEventListener('change',()=>{ [...input.files].forEach(uploadFile); input.value=''; });
  list.addEventListener('click',e=>{ const btn=e.target.closest('.del'); if(!btn) return; const item=btn.closest('.thumb'); const imgId=item&&item.dataset.id; if(imgId) delImage(imgId,item); });
})();
// auto-hide toast
(function(){
  const f = document.getElementById('flash');
  if (!f) return;
  setTimeout(()=>{ try{ f.remove(); }catch(_){} }, 4000);
})();
</script>

<?php
// POZOR: ob_end_flush MUSÍ být v PHP, ne v <script>
ob_end_flush();
safe_include_footer();
?>

