<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$eid = (string)($_GET['id'] ?? '');
if ($eid === '') { http_response_code(400); echo json_encode(['error'=>'bad id']); exit; }

/* --- helpers --- */
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

function detectSeatingMode(array $ev, string $eid): string {
  // 1) primárně režim z editoru
  $sell = strtolower((string)($ev['selling_mode'] ?? ''));
  if ($sell === 'seats') return 'seatmap';
  if ($sell === 'ga')    return 'ga';
  if ($sell === 'mixed') {
    // chytře: když má GA reálnou kapacitu, ukaž GA; jinak seatmap
    $gaCap = getGACapacity($eid);
    $smCap = getSeatmapCapacity($eid);
    if ($gaCap > 0) return 'ga';
    if ($smCap > 0) return 'seatmap';
    return 'ga'; // totální fallback
  }

  // 2) až potom explicitní seating_mode (pokud bys ho někde nastavoval ručně)
  $m = strtolower((string)($ev['seating_mode'] ?? ''));
  if ($m === 'ga' || $m === 'seatmap') return $m;

  // 3) úplný fallback: podle existence seatmapy
  return (getSeatmapCapacity($eid) > 0) ? 'seatmap' : 'ga';
}

/* načti event + režim */
$stE = db()->prepare("
  SELECT id, seating_mode, selling_mode
  FROM events
  WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', '')))
  LIMIT 1
");
$stE->execute([$eid, $eid]);
$ev = $stE->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$eid];

$mode = detectSeatingMode($ev, $eid);

/* spočítej čísla */
if ($mode === 'seatmap') {
  $capacity = getSeatmapCapacity($eid);
  $sold     = getSoldSeatmap($eid);
} else {
  $capacity = getGACapacity($eid);
  $sold     = getSoldGA($eid);
}

echo json_encode([
  'mode'     => $mode,
  'sold'     => (int)$sold,
  'capacity' => (int)$capacity,
], JSON_UNESCAPED_UNICODE);
