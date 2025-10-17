<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php'; // <-- kvůli slugify()
ensure_admin();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmtDate($dt){
  if(!$dt) return '';
  try { $d = new DateTime($dt); return $d->format('d.m.Y H:i'); } catch(Throwable $e){ return $dt; }
}
function data_post_attr(array $payload): string {
  return h(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function fmtMoney(float $v, string $ccy='CZK'): string {
  try {
    return (new \NumberFormatter('cs-CZ', \NumberFormatter::CURRENCY))
      ->formatCurrency(round($v), strtoupper($ccy));
  } catch (\Throwable $e) {
    $sym = strtoupper($ccy)==='EUR' ? '€' : 'Kč';
    return number_format(round($v), 0, ',', ' ') . ' ' . $sym;
  }
}

$id = $_GET['id'] ?? '';
if ($id === '') { http_response_code(400); echo 'Chybí id akce.'; exit; }
$evtId = $id; // <-- budeme používat sjednoceně

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function bad_request($msg='Bad request'){ http_response_code(400); echo $msg; exit; }

function uuidv4(): string {
  $d = random_bytes(16);
  $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
  $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

// ---- POST akce
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['__action'] ?? '';
  $tok    = $_POST['__csrf']   ?? '';
  if (!hash_equals($_SESSION['csrf'] ?? '', $tok)) bad_request('CSRF check failed');

  if ($action === 'duplicate') {
  try {
    // 1) načti zdroj
    $src = db()->prepare("SELECT * FROM events WHERE id=? OR id=UNHEX(REPLACE(?, '-', '')) LIMIT 1");
    $src->execute([$evtId, $evtId]);
    $row = $src->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      header('Location: ?id='.rawurlencode($evtId).'&cloned=0&cloned_err=nenalezena', true, 303);
      exit;
    }

    // 2) nové UUID + unikátní slug
    if (!function_exists('uuidv4')) {
      function uuidv4(){ $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4)); }
    }
    $newId = uuidv4();
    $base  = trim((string)($row['slug'] ?: $row['title'] ?: 'akce'));
    $slugBase = slugify($base) ?: 'akce';
    $candidate = $slugBase.'-kopie';
    $chk = db()->prepare("SELECT 1 FROM events WHERE slug=? LIMIT 1");
    $i=1; while (true) { $chk->execute([$candidate]); if (!$chk->fetchColumn()) break; $candidate = $slugBase.'-kopie-'.(++$i); }
    $newSlug = $candidate;

    // 3) transakce
    try { db()->beginTransaction(); } catch(Throwable $e) {}

    // 4) vlož novou akci (jen existující sloupce – tady jsou bezpečné/common)
    $ins = db()->prepare("
      INSERT INTO events
        (id,title,slug,description,venue_name,address,map_embed_url,
         starts_at,ends_at,timezone,status,selling_mode,
         organizer_name,organizer_email,organizer_phone,organizer_website,organizer_facebook,
         cover_image_url)
      SELECT ?, CONCAT(COALESCE(title,''),' (kopie)'), ?,
             description,venue_name,address,map_embed_url,
             starts_at,ends_at,COALESCE(timezone,'Europe/Prague'),'draft',selling_mode,
             organizer_name,organizer_email,organizer_phone,organizer_website,organizer_facebook,
             cover_image_url
      FROM events
      WHERE id=? OR id=UNHEX(REPLACE(?, '-', ''))
      LIMIT 1
    ");
    $ins->execute([$newId, $newSlug, $evtId, $evtId]);

    // 5) helper na seznam sloupců tabulky (bez info_schema to zkusí SHOW COLUMNS)
    $allCols = (function(string $table){
      try {
        $st = db()->prepare("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$table]);
        $cols = array_map(fn($r)=>$r['COLUMN_NAME'], $st->fetchAll(PDO::FETCH_ASSOC));
        if ($cols) return $cols;
      } catch(Throwable $e) {}
      try {
        $st = db()->prepare("SHOW COLUMNS FROM `$table`");
        $st->execute();
        return array_map(fn($r)=>$r['Field'], $st->fetchAll(PDO::FETCH_ASSOC));
      } catch(Throwable $e) {}
      return [];
    })('event_ticket_types');

    // 6) zkopíruj ticket typy – dynamicky (žádné price_cents/currency napevno)
    $skip = ['id','event_id','created_at','updated_at','deleted_at','created','modified'];
    $copyCols = array_values(array_diff($allCols, $skip));
    if (!empty($copyCols)) {
      $colList = '`event_id`,' . implode(',', array_map(fn($c)=>"`$c`", $copyCols));
      $selList = '?,'.implode(',', array_map(fn($c)=>"t.`$c`", $copyCols));
      $sqlTT = "
        INSERT INTO `event_ticket_types` ($colList)
        SELECT $selList
        FROM `event_ticket_types` t
        WHERE t.event_id = ? OR t.event_id = UNHEX(REPLACE(?, '-', ''))
      ";
      $p = db()->prepare($sqlTT);
      $p->execute([$newId, $evtId, $evtId]);
    }

    // 7) galerie
    db()->prepare("
      INSERT INTO event_images (event_id,url,is_cover,sort_order)
      SELECT ?,url,is_cover,sort_order
      FROM event_images
      WHERE event_id = ? OR event_id = UNHEX(REPLACE(?, '-', ''))
    ")->execute([$newId, $evtId, $evtId]);

    // 8) seatmap (poslední verze → verze 1)
    try {
      db()->prepare("
        INSERT INTO event_seatmaps (event_id,version,schema_json)
        SELECT ?,1,s.schema_json
        FROM event_seatmaps s
        WHERE s.event_id = ? OR s.event_id = UNHEX(REPLACE(?, '-', ''))
        ORDER BY s.version DESC
        LIMIT 1
      ")->execute([$newId, $evtId, $evtId]);
    } catch(Throwable $ignore) {}

    try { db()->commit(); } catch(Throwable $e) {}

    header('Location: /admin/event_edit.php?id='.rawurlencode($newId).'&cloned=1', true, 303);
    exit;

  } catch (Throwable $e) {
    try { db()->rollBack(); } catch(Throwable $e2) {}
    error_log('Duplicate event error: '.$e->getMessage());
    header('Location: ?id='.rawurlencode($evtId).'&cloned=0&cloned_err='.rawurlencode($e->getMessage()), true, 303);
    exit;
  }
}
  if ($action === 'delete') {
    try {
      $q = db()->prepare("
        SELECT COUNT(*) FROM orders
        WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
          AND status IN ('paid','manual_paid')
      ");
      $q->execute([$evtId, $evtId]);
      $hasPaid = (int)$q->fetchColumn() > 0;

      if ($hasPaid) {
        db()->prepare("UPDATE events SET status='archived' WHERE id=? OR id=UNHEX(REPLACE(?, '-', '')) LIMIT 1")->execute([$evtId, $evtId]);
        header('Location: /admin/?deleted=archived', true, 303); exit;
      }

      try { db()->beginTransaction(); } catch(Throwable $e) {}
      db()->prepare("DELETE FROM event_images       WHERE event_id=? OR event_id=UNHEX(REPLACE(?, '-', ''))")->execute([$evtId, $evtId]);
      db()->prepare("DELETE FROM event_ticket_types WHERE event_id=? OR event_id=UNHEX(REPLACE(?, '-', ''))")->execute([$evtId, $evtId]);
      db()->prepare("DELETE FROM event_seatmaps     WHERE event_id=? OR event_id=UNHEX(REPLACE(?, '-', ''))")->execute([$evtId, $evtId]);
      db()->prepare("DELETE FROM events WHERE id=? OR id=UNHEX(REPLACE(?, '-', '')) LIMIT 1")->execute([$evtId, $evtId]);
      try { db()->commit(); } catch(Throwable $e) {}
      header('Location: /admin/?deleted=1', true, 303); exit;

    } catch (Throwable $e) {
      try { db()->rollBack(); } catch(Throwable $e2) {}
      error_log('Delete event error: '.$e->getMessage());
      header('Location: ?id='.rawurlencode((string)$evtId).'&deleted=0', true, 303); exit;
    }
  }
if ($action === 'set_status') {
  $val = $_POST['value'] ?? '';
  $allowed = ['draft','on_sale','sold_out','archived'];
  if (!in_array($val, $allowed, true)) bad_request('Unknown action');

  $u = db()->prepare("UPDATE events SET status=? WHERE id=? OR id=UNHEX(REPLACE(?, '-', '')) LIMIT 1");
  $u->execute([$val, $evtId, $evtId]);

  header('Location: ?id=' . rawurlencode($evtId) . '&status_set=1', true, 303);
  exit;
}

  bad_request('Unknown action');
}


/* ========= GET id – až po POST větvi ========= */
$id = $_GET['id'] ?? '';
if ($id === '') { http_response_code(400); echo 'Chybí id akce.'; exit; }


// Akce
$st = db()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
$st->execute([$id]);
$e = $st->fetch(PDO::FETCH_ASSOC);
// Měna akce – zkusit events.currency, jinak poslední zaplacená objednávka, default CZK
$currency = strtoupper(trim((string)($e['currency'] ?? '')));
if ($currency === '') {
  try {
$qCcy = db()->prepare("
  SELECT currency
  FROM orders
  WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
    AND status='paid'
  ORDER BY created_at DESC
  LIMIT 1
");
$qCcy->execute([$id, $id]);

    $currency = strtoupper((string)($qCcy->fetchColumn() ?: 'CZK'));
  } catch (\Throwable $ex) { $currency = 'CZK'; }
}
if ($currency !== 'EUR') $currency = 'CZK'; // držíme jen CZK/EUR (příp. rozšiř)
if (!$e) { http_response_code(404); echo 'Akce nenalezena.'; exit; }

// Public URL: slug -> id fallback
$slug = trim((string)($e['slug'] ?? ''));
$publicUrl = '/e/' . ($slug !== '' ? rawurlencode($slug) : rawurlencode((string)$e['id']));
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'slagrkoncerty.cz';
$host   = (stripos($host, 'www.') === 0) ? $host : ('www.' . $host);
$absolutePublicUrl = $scheme . '://' . $host . $publicUrl;
/**
 * Universal helpers (MariaDB/MySQL, UUID char(36)|binary(16))
 */
function db_has_column(string $table, string $col): bool {
  try {
    $st = db()->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $st->execute([$table, $col]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) { return false; }
}

/**
 * Kapacita ze seatmapy (nejnovější verze):
 * 1) count(seats), 2) fallback: sum(rows_meta[*].seats)+sum(tables[*].seat_count)
 */
function getSeatmapCapacity(string $eventId): int {
  try {
    $st = db()->prepare("
      SELECT schema_json
      FROM event_seatmaps
      WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
      ORDER BY version DESC
      LIMIT 1
    ");
    $st->execute([$eventId, $eventId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['schema_json'])) return 0;

    $d = json_decode($row['schema_json'], true);
    if (!is_array($d)) return 0;

    if (!empty($d['seats']) && is_array($d['seats'])) return count($d['seats']);

    $cap = 0;
    if (!empty($d['rows_meta']) && is_array($d['rows_meta'])) {
      foreach ($d['rows_meta'] as $meta) $cap += (int)($meta['seats'] ?? 0);
    }
    if (!empty($d['tables']) && is_array($d['tables'])) {
      foreach ($d['tables'] as $t) $cap += (int)($t['seat_count'] ?? 0);
    }
    return $cap;
  } catch (Throwable $e) { return 0; }
}

/** Kapacita GA = sum(event_ticket_types.capacity) */
function getGACapacity(string $eventId): int {
  try {
    $q = db()->prepare("
      SELECT COALESCE(SUM(capacity),0)
      FROM event_ticket_types
      WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
    ");
    $q->execute([$eventId, $eventId]);
    return (int)$q->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/** Prodané sedadlové = SUM(order_items.quantity) s přiřazeným seat_id z paid objednávek */
function getSoldSeatmap(string $eventId): int {
  try {
    $q = db()->prepare("
      SELECT COALESCE(SUM(oi.quantity),0)
      FROM order_items oi
      JOIN orders o ON o.id = oi.order_id
      WHERE (oi.event_id = ? OR oi.event_id = UNHEX(REPLACE(?, '-', '')))
        AND oi.seat_id IS NOT NULL
        AND o.status IN ('paid','manual_paid')
    ");
    $q->execute([$eventId, $eventId]);
    return (int)$q->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/** Prodané GA = SUM(order_items.quantity) BEZ seat_id (tj. volné vstupenky) z paid objednávek */
function getSoldGA(string $eventId): int {
  try {
    $q = db()->prepare("
      SELECT COALESCE(SUM(oi.quantity),0)
      FROM order_items oi
      JOIN orders o ON o.id = oi.order_id
      WHERE (oi.event_id = ? OR oi.event_id = UNHEX(REPLACE(?, '-', '')))
        AND (oi.seat_id IS NULL OR oi.seat_id = '')
        AND o.status IN ('paid','manual_paid')
    ");
    $q->execute([$eventId, $eventId]);
    return (int)$q->fetchColumn();
  } catch (Throwable $e) { return 0; }
}

/**
 * Detekce režimu pro akci:
 * 1) Pokud existuje events.seating_mode, respektuj 'seatmap' / 'ga'.
 * 2) Jinak: když seatmap capacity > 0 → 'seatmap', jinak 'ga'.
 */
function detectSeatingMode(array $eventRow, string $eventId): string {
  // 1) Nový preferovaný sloupec z event_edit.php
  $selling = strtolower((string)($eventRow['selling_mode'] ?? ''));
  if ($selling === 'seats')  return 'seatmap';
  if ($selling === 'ga')     return 'ga';
  if ($selling === 'mixed')  return (getSeatmapCapacity($eventId) > 0) ? 'seatmap' : 'ga';

  // 2) Legacy kompatibilita (kdyby ještě někde zůstal seating_mode)
  $legacy = strtolower((string)($eventRow['seating_mode'] ?? ''));
  if ($legacy === 'seatmap' || $legacy === 'ga') return $legacy;

  // 3) Fallback
  return (getSeatmapCapacity($eventId) > 0) ? 'seatmap' : 'ga';
}



// --- VÝPOČET PODLE REŽIMU ---
$eid  = (string)$id;
$mode = detectSeatingMode($e, $eid);

if ($mode === 'seatmap') {
  $capacityTotal = getSeatmapCapacity($eid);
  $soldTickets   = getSoldSeatmap($eid);
} else {
  $capacityTotal = getGACapacity($eid);
  $soldTickets   = getSoldGA($eid);
}
// --- SEGMENTY pro progress (typeRows) ---
// Default: GA i Seatmap připravíme podle toho, co akce používá
$typeRows = [];

if ($mode === 'seatmap') {
  // Sedadlový rozpad podle tieru (s.price_tier_code) + paid prodeje
  try {
    $sqlSeg = "
      SELECT
        COALESCE(ett.id, 0)                   AS id,
        COALESCE(ett.name, s.price_tier_code) AS name,
        COALESCE(ett.color, '#2563eb')        AS color,
        COUNT(*)                               AS capacity,
        COALESCE(SUM(CASE WHEN o.status IN ('paid','manual_paid') THEN 1 ELSE 0 END), 0) AS sold
      FROM seats s
      LEFT JOIN event_ticket_types ett
             ON ett.event_id = s.event_id
            AND ett.code = s.price_tier_code
      LEFT JOIN order_items oi ON oi.seat_id = s.id AND oi.event_id = s.event_id
      LEFT JOIN orders o ON o.id = oi.order_id AND o.event_id = s.event_id
      WHERE s.event_id = ?
        AND (s.status IS NULL OR s.status NOT IN ('blocked','disabled'))
      GROUP BY id, name, color
      ORDER BY capacity DESC, name ASC
    ";
    $qTypes = db()->prepare($sqlSeg);
    $qTypes->execute([$eid]);
    $typeRows = $qTypes->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fallback – pokud by zatím nebyly paid vazby do orders, použij seats.status
    $sumSold = array_sum(array_map(fn($r)=> (int)$r['sold'], $typeRows));
    if ($sumSold === 0) {
      $sqlSegFallback = "
        SELECT
          COALESCE(ett.id, 0)                   AS id,
          COALESCE(ett.name, s.price_tier_code) AS name,
          COALESCE(ett.color, '#2563eb')        AS color,
          COUNT(*)                               AS capacity,
          SUM(CASE WHEN s.status IN ('sold','booked','occupied','paid','reserved_paid') THEN 1 ELSE 0 END) AS sold
        FROM seats s
        LEFT JOIN event_ticket_types ett
               ON ett.event_id = s.event_id
              AND ett.code = s.price_tier_code
        WHERE s.event_id = ?
          AND (s.status IS NULL OR s.status NOT IN ('blocked','disabled'))
        GROUP BY id, name, color
        ORDER BY capacity DESC, name ASC
      ";
      $qTypes2 = db()->prepare($sqlSegFallback);
      $qTypes2->execute([$eid]);
      $typeRows = $qTypes2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $ex) {
    $typeRows = [];
  }

} else {
  // GA rozpad podle event_ticket_types (capacity z ett, sold z order_items bez seat_id)
  try {
    // ZVOL správný JOIN podle tvého schématu:
    $onClause = "oi.ticket_type_id = ett.id";      // výchozí
    // $onClause = "oi.ticket_type_code = ett.code"; // pokud máš v OI kód
    // $onClause = "oi.ticket_code = ett.code";      // jiná alternativa

    $sqlGA = "
      SELECT
        ett.id,
        ett.name,
        COALESCE(ett.color, '#2563eb') AS color,
        COALESCE(ett.capacity, 0)      AS capacity,
        COALESCE(SUM(CASE WHEN o.status IN ('paid','manual_paid') THEN oi.quantity ELSE 0 END), 0) AS sold
      FROM event_ticket_types ett
      LEFT JOIN order_items oi
             ON (oi.event_id = ett.event_id)
            AND ($onClause)
            AND (oi.seat_id IS NULL OR oi.seat_id = '')
      LEFT JOIN orders o ON o.id = oi.order_id
      WHERE (ett.event_id = ? OR ett.event_id = UNHEX(REPLACE(?, '-', '')))
      GROUP BY ett.id, ett.name, color, capacity
      ORDER BY capacity DESC, name ASC
    ";
    $qTypes = db()->prepare($sqlGA);
    $qTypes->execute([$eid, $eid]);
    $typeRows = $qTypes->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $ex) {
    $typeRows = [];
  }
}


$remainingTotal = max(0, $capacityTotal - $soldTickets);

// (Tvoje ostatní KPI – ordersCount, todayRevenue, totalRevenue – nech tak, jak máš.)


// --- Doplňkové KPI ---
try {
  // Počet objednávek (zaplacené)
  $q2 = db()->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
      AND status = 'paid'
  ");
  $q2->execute([$eid, $eid]);
  $ordersCount = (int)$q2->fetchColumn();
} catch (Throwable $ex) { $ordersCount = 0; }

try {
  // Dnešní prodej (částka)
  $q3 = db()->prepare("
    SELECT COALESCE(SUM(
      CASE
        WHEN total_cents >= 10000 THEN ROUND(total_cents/100.0)
        ELSE total_cents
      END
    ), 0)
    FROM orders
    WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
      AND status = 'paid'
      AND DATE(created_at) = CURDATE()
  ");
  $q3->execute([$eid, $eid]);
  $todayRevenue = (float)$q3->fetchColumn();
} catch (Throwable $ex) { $todayRevenue = 0.0; }


try {
  // Celkový prodej (částka)
$q4 = db()->prepare("
  SELECT COALESCE(SUM(
    CASE 
      WHEN total_cents >= 10000 THEN ROUND(total_cents/100.0) 
      ELSE total_cents 
    END
  ), 0)
  FROM orders 
  WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
    AND status = 'paid'
");
$q4->execute([$id, $id]);

  $totalRevenue = (float)$q4->fetchColumn();
} catch(Throwable $ex){ $totalRevenue = 0.0; }
// Poslední objednávky (TOP 6)
try {
$qLast = db()->prepare("
  SELECT id, customer_name, total_cents, currency, created_at
  FROM orders
  WHERE (event_id = ? OR event_id = UNHEX(REPLACE(?, '-', '')))
    AND status='paid'
  ORDER BY created_at DESC
  LIMIT 6
");
$qLast->execute([$id, $id]);

  $lastOrders = $qLast->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $ex) { $lastOrders = []; }

// Status badge
$blue = '#2563eb';
$statusMap = [
  'draft'    => ['Koncept','#6b7280','#f3f4f6'],
  'on_sale'  => ['V prodeji',$blue,'#dbeafe'],
  'sold_out' => ['Vyprodáno','#991b1b','#fee2e2'],
  'archived' => ['Archiv','#374151','#e5e7eb'],
];
[$statusLabel,$statusFG,$statusBG] = $statusMap[$e['status']] ?? ['Neznámý','#111','#eee'];

$admin_event_title = $event['title'] ?? '';
$admin_back_href   = '/admin/';    // ← zpět na index akcí
$admin_show_back   = true;         // ← vynutit zobrazení tlačítka Zpět

include __DIR__.'/_header.php';

?>
<style>
:root{
  --bg:#f7f8fb; --panel:#fff; --text:#0b1220; --muted:#5b677a; --border:#e6e9ef;
  --accent:#2563eb; --accent-600:#1d4ed8; --radius:14px; --shadow:0 8px 30px rgba(3,14,38,.06);
}
.wrap{max-width:1180px;margin:0 auto;padding:22px 16px}
h1{margin:0 0 12px}

/* Karty */
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow)}
.card + .card{margin-top:18px}

/* GRID: rail (280px) + content column (stack) */
.grid{
  display:grid;
  grid-template-columns: 300px 1fr;
  gap:18px;
  align-items:start;
}
@media (max-width:1100px){ .grid{ grid-template-columns:1fr } .rail{ order:-1 } }
.content-col{ display:grid; grid-auto-rows:min-content; gap:18px; }

/* RAIL (akční panel) */
.rail{ position:sticky; top:84px }
.rail-card{
  background:linear-gradient(180deg,#ffffff 0%, #f8fbff 100%);
  border:1px solid #e6ecf5;
  border-radius:14px;
  box-shadow:0 10px 28px rgba(3,14,38,.06);
  overflow:hidden;
}
.rail-head{
  display:flex; align-items:center; gap:10px;
  padding:12px 14px;
  font-weight:800; font-size:14px; letter-spacing:.2px;
  color:#0b1220; background:#f3f7ff; border-bottom:1px solid #e6ecf5;
}
.rail-head i{ color:#2563eb }
.rail-actions{ display:flex; flex-direction:column; padding:10px; gap:8px }
.rail-sep{ height:1px; background:#eef2f7; margin:6px 2px }

.rbtn{
  display:grid; grid-template-columns:auto 1fr; grid-template-rows:auto auto;
  align-items:center; gap:3px 10px; padding:10px 12px;
  border-radius:12px; border:1px solid #e7edf6; background:#fff;
  text-decoration:none; box-shadow:0 1px 0 rgba(16,24,40,.04);
  transition: transform .06s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
}
.rbtn i{ grid-row:1 / span 2; font-size:16px; color:#2563eb; padding:4px; }
.rbtn .t{ font-weight:700; color:#0b1220 }
.rbtn .s{ font-size:12px; color:#64748b }
.rbtn:hover{ transform:translateY(-1px); box-shadow:0 6px 18px rgba(2,8,23,.08); background:#f7faff }
.rbtn.primary{ background:#2563eb; border-color:#2563eb; color:#fff }
.rbtn.primary .t,.rbtn.primary .s,.rbtn.primary i{ color:#fff }
.rbtn.primary:hover{ background:#1d4ed8; border-color:#1d4ed8 }
.rbtn.warn{ background:#fffdf6; border-color:#fde68a }
.rbtn.warn i{ color:#b45309 } .rbtn.warn .t{ color:#92400e } .rbtn.warn .s{ color:#b45309 }
.rbtn.danger{ background:#fff7f7; border-color:#fecaca }
.rbtn.danger i{ color:#dc2626 } .rbtn.danger .t{ color:#991b1b } .rbtn.danger .s{ color:#dc2626 }
.rbtn[aria-busy="true"]{ opacity:.6; pointer-events:none }

.rail-utils{ border-top:1px dashed #e6ecf5; padding:10px 12px; background:#fbfdff }
.mini{ display:flex; align-items:center; gap:8px; padding:6px 0; font-size:13px }
.mini i{ color:#667085 }
.linkish{ color:#2563eb; background:none; border:none; padding:0; cursor:pointer; font:inherit }
.linkish:hover{ text-decoration:underline }

/* TOAST */
.toast{ position:fixed; z-index:9999; right:16px; bottom:16px; background:#0f172a; color:#fff;
  padding:10px 12px; border-radius:10px; box-shadow:0 10px 30px rgba(2,6,23,.3); font-size:13px;
  opacity:0; transform:translateY(6px); transition:opacity .2s, transform .2s }
.toast.show{ opacity:1; transform:translateY(0) }

/* INFO CARD (náhled + metadata vedle sebe) */
.info-grid{
  display:grid;
  grid-template-columns:minmax(280px, 48%) 1fr;
  gap:16px;
}
@media (max-width:860px){ .info-grid{ grid-template-columns:1fr } }

.cover{position:relative;aspect-ratio:16/9;background:#f3f4f6;border:1px solid var(--border);border-radius:12px;overflow:hidden}
.cover img{width:100%;height:100%;object-fit:cover;display:block}
.badge{
  position:absolute; top:10px; left:10px; display:inline-flex; align-items:center; gap:8px;
  padding:6px 10px; border-radius:999px; background:#eef2ff; color:#1e293b;
  border:1px solid rgba(0,0,0,.06); font-weight:600
}

.meta{color:#475569;font-size:14px; display:grid; gap:8px}
.meta .row{display:flex; gap:8px; align-items:flex-start}
.meta .row .label{min-width:90px; color:#6b7280}

/* KPI + Progress */
.kpis{display:grid; grid-template-columns:repeat(4,1fr); gap:12px}
@media (max-width:960px){ .kpis{ grid-template-columns:repeat(2,1fr) } }
.kpi{background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px;box-shadow:var(--shadow)}
.kpi .name{font-size:12px;color:#64748b;margin-bottom:6px}
.kpi .val{font-size:22px;font-weight:800;color:#0b1220}

.progress-card{border:1px solid var(--border);background:#fff;border-radius:12px;box-shadow:var(--shadow);padding:14px;margin-top:14px}
.progress-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.progress-head .title{font-weight:700}
.progress-head .nums{font-variant-numeric:tabular-nums;color:#475569}
.gbar{position:relative;height:14px;background:#eef2ff;border:1px solid #e5e7eb;border-radius:999px;overflow:hidden}
.gbar > .fill{position:absolute;left:0;top:0;bottom:0;width:0;transition:width .6s cubic-bezier(.2,.8,.2,1);background:linear-gradient(90deg,#2563eb,#1d4ed8)}
.seg-list{display:flex;flex-direction:column;gap:10px;margin-top:12px}
.seg{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center}
.seg .meta{display:flex;align-items:center;gap:8px;font-size:13px}
.seg .dot{width:12px;height:12px;border-radius:999px;border:1px solid rgba(0,0,0,.08)}
.seg .nums{font-variant-numeric:tabular-nums;color:#475569}
.seg .bar{height:10px;background:#f1f5f9;border-radius:999px;position:relative;overflow:hidden;border:1px solid #e5e7eb}
.seg .bar > span{position:absolute;left:0;top:0;bottom:0;width:0;transition:width .6s cubic-bezier(.2,.8,.2,1);background:#2563eb}

/* Utility tlačítka (tabulky atd.) */
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid var(--accent);background:var(--accent);color:#fff;font-weight:600;text-decoration:none}
.btn:hover{background:var(--accent-600)}
.btn.outline{background:#fff;color:var(--accent)}
.btn.gray{background:#f3f4f6;border-color:#d1d5db;color:#111}
.subtle{color:#6b7280;font-size:13px}
</style>

<?php
// Bezpečný absolutní public URL (kdyby nebylo už nadefinované)
if (!isset($absolutePublicUrl)) {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'www.slagrkoncerty.cz';
  $absolutePublicUrl = $scheme.'://'.$host.$publicUrl;
}
?>

<div class="wrap">
  <h1><?= h($e['title'] ?: 'Bez názvu') ?></h1>

  <?php if (isset($_GET['duped'])): ?>
    <div class="card" style="margin-bottom:12px;background:#ecfeff;border-color:#a5f3fc;color:#064e3b">
      <?= $_GET['duped']=='0' ? 'Duplikace selhala.' : 'Akce byla úspěšně duplikována.' ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="margin-bottom:12px;background:#fef3c7;border-color:#fde68a;color:#7c2d12">
      <?= $_GET['deleted']==='1' ? 'Akce byla smazána.' : ($_GET['deleted']==='archived' ? 'Akce má zaplacené objednávky — byla archivována.' : 'Smazání selhalo.') ?>
    </div>
  <?php endif; ?>

  <div class="grid">
    <!-- LEVÝ RAIL -->
    <aside class="rail">
      <div class="rail-card">
        <div class="rail-actions">
          <a class="rbtn primary" href="/admin/event_edit.php?id=<?= h($id) ?>">
            <i class="fa-regular fa-pen-to-square"></i>
            <span class="t">Upravit</span><span class="s">formulář akce</span>
          </a>

          <a class="rbtn" href="/admin/tickets_choice.php?event=<?= h($e['id']) ?>">
            <i class="fa-solid fa-chair"></i>
            <span class="t">Vstupenky & sedadla</span><span class="s">správa typů</span>
          </a>

          <a class="rbtn" href="/admin/orders.php?event=<?= h($e['id']) ?>">
            <i class="fa-regular fa-rectangle-list"></i>
            <span class="t">Objednávky</span><span class="s">přehled</span>
          </a>

          <a class="rbtn" href="<?= h($publicUrl) ?>" target="_blank" rel="noopener">
            <i class="fa-regular fa-eye"></i>
            <span class="t">Náhled</span><span class="s">veřejná stránka</span>
          </a>

          <div class="rail-sep"></div>

          <!-- Duplikace -->
          <a href="#" class="rbtn"
             data-post="<?= data_post_attr(['__action'=>'duplicate']) ?>">
            <i class="fa-regular fa-clone"></i>
            <span class="t">Duplikovat</span><span class="s">vytvoří kopii</span>
          </a>

          <!-- Publikovat / Archivovat -->
          <?php
            $next = match($e['status'] ?? 'draft') {
              'draft'    => ['on_sale','Publikovat (V prodeji)'],
              'on_sale'  => ['archived','Archivovat'],
              'sold_out' => ['archived','Archivovat'],
              default    => ['on_sale','Zpět do prodeje'],
            };
          ?>
          <a href="#" class="rbtn warn"
             data-post="<?= data_post_attr(['__action'=>'set_status','value'=>$next[0]]) ?>">
            <i class="fa-regular fa-flag"></i>
            <span class="t"><?= h($next[1]) ?></span><span class="s">rychlá změna stavu</span>
          </a>

          <!-- Smazání -->
          <a href="#" class="rbtn danger"
             data-post="<?= data_post_attr(['__action'=>'delete']) ?>"
             data-confirm="Opravdu chcete akci smazat? Pokud má zaplacené objednávky, pouze se archivuje.">
            <i class="fa-regular fa-trash-can"></i>
            <span class="t">Smazat</span><span class="s">nevratné</span>
          </a>
        </div>

        <div class="rail-utils">
          <div class="mini">
            <i class="fa-regular fa-copy"></i>
            <button type="button" class="linkish" data-copy="<?= h($absolutePublicUrl) ?>">Kopírovat veřejnou URL</button>
          </div>
          <div class="mini">
            <i class="fa-regular fa-file-excel"></i>
            <a class="linkish" href="/admin/orders_export.php?event=<?= h($e['id']) ?>">Export objednávek (CSV)</a>
          </div>
        </div>
      </div>
    </aside>

    <!-- PRAVÝ OBSAHOVÝ SLOUPEC (stack) -->
    <div class="content-col">
      <!-- HORNÍ KARTA: náhled + metadata -->
      <section class="card">
        <div class="info-grid">
          <div class="cover">
            <?php if(!empty($e['cover_image_url'])): ?>
              <img src="<?= h($e['cover_image_url']) ?>" alt="">
            <?php else: ?>
              <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#94a3b8;">Bez náhledu</div>
            <?php endif; ?>
            <?php
              $blue = '#2563eb';
              $statusMap = [
                'draft'    => ['Koncept','#6b7280','#f3f4f6'],
                'on_sale'  => ['V prodeji',$blue,'#dbeafe'],
                'sold_out' => ['Vyprodáno','#991b1b','#fee2e2'],
                'archived' => ['Archiv','#374151','#e5e7eb'],
              ];
              [$statusLabel,$statusFG,$statusBG] = $statusMap[$e['status']] ?? ['Neznámý','#111','#eee'];
            ?>
            <div class="badge" style="background:<?=h($statusBG)?>;color:<?=h($statusFG)?>">
              <span style="width:8px;height:8px;border-radius:999px;background:<?=h($statusFG)?>"></span><?= h($statusLabel) ?>
            </div>
          </div>

          <div>
            <?php $currentMode = $mode; ?>
            <div style="margin:0 0 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <span data-mode-pill
                    style="display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;
                           border:1px solid #e5e7eb;background:#f8fafc;font-weight:600;color:#334155">
                Režim: <?= $currentMode==='seatmap' ? 'Sedadla' : 'Volné vstupenky (GA)' ?>
              </span>
            </div>

            <div class="meta">
              <div class="row"><span class="label">Datum:</span><span><?= h(fmtDate($e['starts_at'])) ?></span></div>
              <?php if(!empty($e['venue_name'])): ?><div class="row"><span class="label">Místo:</span><span><?= h($e['venue_name']) ?></span></div><?php endif; ?>
              <?php if(!empty($e['address'])):    ?><div class="row"><span class="label">Adresa:</span><span><?= h($e['address']) ?></span></div><?php endif; ?>
              <?php if(!empty($e['slug'])):       ?><div class="row"><span class="label">Slug:</span><span><code><?= h($e['slug']) ?></code></span></div><?php endif; ?>
              <div class="row"><span class="label">ID:</span><span><code><?= h($e['id']) ?></code></span></div>
            </div>
          </div>
        </div>
      </section>

      <!-- DOLNÍ KARTA: prodeje + poslední objednávky -->
      <section class="card">
        <h3 style="margin:0 0 10px">Přehled prodejů</h3>

        <div class="kpis">
          <div class="kpi"><div class="name">Prodané vstupenky</div><div class="val"><?= number_format($soldTickets, 0, ',', ' ') ?></div></div>
          <div class="kpi"><div class="name">Počet objednávek</div><div class="val"><?= number_format($ordersCount, 0, ',', ' ') ?></div></div>
          <div class="kpi"><div class="name">Dnešní prodej</div><div class="val"><?= fmtMoney($todayRevenue, $currency) ?></div></div>
          <div class="kpi"><div class="name">Celkový prodej</div><div class="val"><?= fmtMoney($totalRevenue, $currency) ?></div></div>
        </div>

        <div class="progress-card" id="salesProgress" data-event-id="<?= h($e['id']) ?>">
          <div class="progress-head">
            <div class="title">Obsazenost akce</div>
            <div class="nums">
              <span class="sold"><?= number_format($soldTickets,0,',',' ') ?></span> /
              <span class="cap"><?= number_format($capacityTotal,0,',',' ') ?></span>
              &nbsp;(<span class="pct"><?= ($capacityTotal>0 ? round($soldTickets*100/$capacityTotal) : 0) ?></span>%)
            </div>
          </div>
          <div class="gbar"><div class="fill" style="width: <?= $capacityTotal>0 ? ($soldTickets*100/$capacityTotal) : 0 ?>%"></div></div>

          <?php if($typeRows): ?>
            <div class="seg-list">
              <?php foreach($typeRows as $t):
                $cap = (int)$t['capacity']; $sold = (int)$t['sold'];
                $pct = $cap>0 ? min(100, $sold*100/$cap) : 0;
                $col = $t['color'] ?: '#2563eb';
              ?>
              <div class="seg" data-type-id="<?= (int)$t['id'] ?>">
                <div class="meta"><span class="dot" style="background: <?= h($col) ?>"></span><strong><?= h($t['name'] ?: $t['code']) ?></strong></div>
                <div class="nums"><span class="sold"><?= $sold ?></span>/<span class="cap"><?= $cap ?></span> (<span class="pct"><?= round($pct) ?></span>%)</div>
                <div class="bar" style="grid-column:1 / -1"><span style="width: <?= $pct ?>%; background: <?= h($col) ?>"></span></div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="margin-top:16px">
          <h3 style="margin:0 0 10px">Posledních 6 objednávek</h3>

          <?php if (!$lastOrders): ?>
            <div class="subtle" style="padding:10px;border:1px solid var(--border);border-radius:10px;background:#fbfcff">
              Zatím žádné zaplacené objednávky.
            </div>
          <?php else: ?>
            <div style="overflow:auto;border:1px solid var(--border);border-radius:10px">
              <table style="width:100%;border-collapse:separate;border-spacing:0">
                <thead style="background:#f8fafc;">
                  <tr>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;color:#475569;width:90px">#</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;color:#475569">Zákazník</th>
                    <th style="text-align:left;padding:10px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;color:#475569;width:160px">Datum</th>
                    <th style="text-align:right;padding:10px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;color:#475569;width:140px">Částka</th>
                    <th style="text-align:right;padding:10px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;color:#475569;width:70px"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($lastOrders as $o):
                    $amtRaw = (int)($o['total_cents'] ?? 0);
                    $amtVal = ($amtRaw >= 10000) ? round($amtRaw/100.0) : $amtRaw;
                    $ccyRow = strtoupper((string)($o['currency'] ?? $currency ?: 'CZK'));
                  ?>
                  <tr>
                    <td style="padding:10px;border-bottom:1px solid var(--border);font-variant-numeric:tabular-nums">
                      <a href="/admin/order.php?id=<?= h($o['id']) ?>" style="text-decoration:none;color:#2563eb">#<?= h($o['id']) ?></a>
                    </td>
                    <td style="padding:10px;border-bottom:1px solid var(--border)"><?= h($o['customer_name'] ?: '—') ?></td>
                    <td style="padding:10px;border-bottom:1px solid var(--border);color:#475569"><?= h(fmtDate($o['created_at'])) ?></td>
                    <td style="padding:10px;border-bottom:1px solid var(--border);text-align:right;font-weight:700"><?= fmtMoney($amtVal, $ccyRow) ?></td>
                    <td style="padding:10px;border-bottom:1px solid var(--border);text-align:right">
                      <a href="/admin/order.php?id=<?= h($o['id']) ?>" class="btn outline" style="padding:6px 10px;border-radius:8px;font-size:12px">Detail</a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div style="margin-top:10px;display:flex;justify-content:flex-end">
              <a class="btn gray" href="/admin/orders.php?event=<?= h($e['id']) ?>" style="background:#eef2ff;border-color:#dbeafe;color:#1d4ed8">
                Zobrazit všechny objednávky
              </a>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</div>


<script>
(function(){
  const box = document.getElementById('salesProgress');
  if(!box) return;

  function animateWidth(el, pct){
    const clamped = Math.max(0, Math.min(100, pct));
    requestAnimationFrame(()=> el.style.width = clamped + '%');
  }

  // postaví (nebo přestaví) celý seznam segmentů
  function buildSegList(types) {
    let list = box.querySelector('.seg-list');
    if (!list) {
      list = document.createElement('div');
      list.className = 'seg-list';
      box.appendChild(list);
    }
    let html = '';
    (types || []).forEach(t => {
      const id   = Number(t.id || 0);
      const cap  = Number(t.capacity || 0);
      const sold = Number(t.sold || 0);
      const pct  = cap > 0 ? Math.min(100, Math.round(sold*100/cap)) : 0;
      const col  = t.color || '#2563eb';
      const name = t.name || (t.code || '');
      html += `
        <div class="seg" data-type-id="${id}">
          <div class="meta">
            <span class="dot" style="background:${col}"></span>
            <strong>${name}</strong>
          </div>
          <div class="nums"><span class="sold">${sold}</span>/<span class="cap">${cap}</span> (<span class="pct">${pct}</span>%)</div>
          <div class="bar" style="grid-column:1 / -1">
            <span style="width:${pct}%; background:${col}"></span>
          </div>
        </div>`;
    });
    list.innerHTML = html;
  }

  // držíme si poslední režim i “podpis” segmentů
  let prevMode = null;
  let prevKey  = '';

  function currentKey(data){
    // klíč měníme, když se změní režim nebo “sada” segmentů (id+name)
    const segKey = (data.types||[])
      .map(t => `${Number(t.id||0)}:${t.name||t.code||''}`)
      .sort()
      .join('|');
    return `${data.mode||''}##${segKey}`;
  }

  function renderAll(data){
    // 1) celková lišta
    const sold = +data.sold || 0;
    const cap  = +data.capacity || 0;
    const pct  = cap > 0 ? Math.round(sold*100/cap) : 0;

    box.querySelector('.sold').textContent = sold.toLocaleString('cs-CZ');
    box.querySelector('.cap').textContent  = cap.toLocaleString('cs-CZ');
    box.querySelector('.pct').textContent  = pct;
    animateWidth(box.querySelector('.gbar .fill'), pct);

    // 2) segmenty – rebuild pokud se změnil režim NEBO podpis segmentů
    const key = currentKey(data);
    if (data.mode !== prevMode || key !== prevKey) {
      buildSegList(data.types);
      prevMode = data.mode || null;
      prevKey  = key;
    } else {
      // jen aktualizace čísel a šířek existujících řádků
      const byId = {};
      (data.types||[]).forEach(t => byId[Number(t.id||0)] = t);
      box.querySelectorAll('.seg').forEach(seg=>{
        const id  = Number(seg.getAttribute('data-type-id') || 0);
        const row = byId[id];
        if(!row) return;
        const soldT = +row.sold || 0;
        const capT  = +row.capacity || 0;
        const pctT  = capT>0 ? Math.round(soldT*100/capT) : 0;
        seg.querySelector('.sold').textContent = soldT;
        seg.querySelector('.cap').textContent  = capT;
        seg.querySelector('.pct').textContent  = pctT;
        const bar = seg.querySelector('.bar > span');
        if (bar) {
          bar.style.background = row.color || '#2563eb';
          animateWidth(bar, pctT);
        }
      });
    }

    // 3) badge s režimem (volitelné)
    if (data.mode) {
      const pill = document.querySelector('[data-mode-pill]');
      if (pill) pill.textContent = (data.mode === 'seatmap') ? 'Sedadla' : 'Volné vstupenky (GA)';
    }
  }

  // hezký úvodní progress
  setTimeout(()=>{
    const initFill = box.querySelector('.gbar .fill');
    initFill && (initFill.style.width = initFill.style.width || '0%');
  }, 30);

  // polling
  const eventId = box.getAttribute('data-event-id');
  async function tick(){
    try{
      const res = await fetch(`/admin/event_stats.php?id=${encodeURIComponent(eventId)}`, { cache:'no-store' });
      if(!res.ok) return;
      const json = await res.json();
      if(json && !json.error) renderAll(json);
    }catch(e){}
  }
  tick();
  const iv = setInterval(tick, 10000);
  window.addEventListener('beforeunload', ()=> clearInterval(iv));
})();
</script>
<script>
// Copy-to-clipboard s toasty
(function(){
  function toast(msg){
    const t = document.createElement('div');
    t.className = 'toast'; t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(()=> t.classList.add('show'));
    setTimeout(()=>{ t.classList.remove('show'); setTimeout(()=> t.remove(),180); }, 1600);
  }
  document.querySelectorAll('[data-copy]').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      const txt = btn.getAttribute('data-copy') || '';
      try {
        await navigator.clipboard.writeText(txt);
        toast('URL zkopírována do schránky');
      } catch(e){
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta); ta.select();
        try{ document.execCommand('copy'); toast('URL zkopírována do schránky'); } catch(_) {}
        ta.remove();
      }
    });
  });
})();
</script>
<script>
(function(){
  const CSRF   = "<?= h($csrf) ?>";
  const EVENT_ID = "<?= h($id) ?>";

  // Jeden delegovaný listener pro všechny [data-post]
  document.addEventListener('click', function(e){
    const btn = e.target.closest('[data-post]');
    if (!btn) return;

    e.preventDefault();

    // confirm?
    const confirmMsg = btn.getAttribute('data-confirm');
    if (confirmMsg && !confirm(confirmMsg)) return;

    // už běží?
    if (btn.dataset.busy === '1') return;
    btn.dataset.busy = '1';
    btn.setAttribute('aria-busy','true');


    // přečti payload
    let payload = {};
    try { payload = JSON.parse(btn.getAttribute('data-post') || '{}'); } catch(_) {}

    // vyrobíme a odešleme formulář POST
    const f = document.createElement('form');
    f.method = 'POST';
    // posíláme na stejnou URL (pathname + query)
    f.action = location.pathname + location.search;

    const hi = (n,v)=>{ const i=document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; f.appendChild(i); };

    hi('__csrf', CSRF);
    hi('id', EVENT_ID);

    Object.keys(payload).forEach(k => hi(k, payload[k]));

    document.body.appendChild(f);
    f.submit();
  }, {passive:false});
})();
</script>


<?php include __DIR__ . '/_footer.php'; ?>
