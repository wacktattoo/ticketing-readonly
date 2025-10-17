<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
ensure_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$eid = (string)($_GET['id'] ?? '');
if ($eid === '') { http_response_code(400); echo json_encode(['error'=>'bad id']); exit; }

/* ===== Helpers z detailu (zkrácené kopie) ===== */
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
      foreach ($d['rows_meta'] as $m) $cap += (int)($m['seats'] ?? 0);
    }
    if (!empty($d['tables']) && is_array($d['tables'])) {
      foreach ($d['tables'] as $t) $cap += (int)($t['seat_count'] ?? 0);
    }
    return $cap;
  } catch (Throwable $e) { return 0; }
}
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
// PŮVODNĚ bylo: function detectSeatingMode(array $eventRow): string { ... }
function detectSeatingMode(array $eventRow, string $eventId): string {
  // 1) Primárně respektuj selling_mode z editoru
  $sell = strtolower((string)($eventRow['selling_mode'] ?? ''));
  if ($sell === 'seats') return 'seatmap'; // mapování na staré názvy
  if ($sell === 'ga')    return 'ga';
  if ($sell === 'mixed') {
    // preferuj sedadla, pokud seatmapa má kapacitu; jinak GA, pokud má kapacitu
    $hasSeatmap = (getSeatmapCapacity($eventId) > 0);
    if ($hasSeatmap) return 'seatmap';
    if (getGACapacity($eventId) > 0) return 'ga';
    // když není nic, padni na fallback níže
  }

  // 2) Legacy podpora (kdyby někde ještě žilo seating_mode)
  $legacy = strtolower((string)($eventRow['seating_mode'] ?? ''));
  if ($legacy === 'seatmap' || $legacy === 'ga') return $legacy;

  // 3) Poslední fallback: podle existence seatmapy
  return (getSeatmapCapacity($eventId) > 0) ? 'seatmap' : 'ga';
}


/* Načti event kvůli seating_mode */
$stE = db()->prepare("SELECT * FROM events WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', ''))) LIMIT 1");
$stE->execute([$eid, $eid]);
$ev = $stE->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$eid];

$mode = detectSeatingMode($ev, $eid);

$capacity = 0; $sold = 0; $types = [];

if ($mode === 'seatmap') {
  $capacity = getSeatmapCapacity($eid);
  $sold     = getSoldSeatmap($eid);

  // Segmenty ze sedadel
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
    $types = $qTypes->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sumSold = array_sum(array_map(fn($r)=> (int)$r['sold'], $types));
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
      $types = $qTypes2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $ex) {
    $types = [];
  }

} else {
  $capacity = getGACapacity($eid);
  $sold     = getSoldGA($eid);

  // Segmenty z event_ticket_types + GA order_items
  try {
    // vyber správný onClause podle schématu:
    $onClause = "oi.ticket_type_id = ett.id";      // default
    // $onClause = "oi.ticket_type_code = ett.code";
    // $onClause = "oi.ticket_code = ett.code";

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
    $types = $qTypes->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $ex) {
    $types = [];
  }
}

echo json_encode([
  'mode'     => $mode,        // ← přidej tohle
  'sold'     => (int)$sold,
  'capacity' => (int)$capacity,
  'types'    => $types,
], JSON_UNESCAPED_UNICODE);

