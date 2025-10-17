<?php
// public/hold.php — dočasné držení sedadel + GA vstupenek
require_once __DIR__ . '/../inc/db.php';
header('Content-Type: application/json');

// identifikace "držitele" (použijeme PHP session)
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$sid = session_id() ?: bin2hex(random_bytes(16));

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$slug  = $input['slug']  ?? '';
$seats = $input['seats'] ?? [];               // [ "A-1", "A-2", ... ]
$ga    = $input['ga']    ?? [];               // [ { type_id, qty }, ... ]
$ccy   = strtoupper($input['ccy'] ?? 'CZK');  // případně pro další logiku

// 1) Najdi akci
$st = db()->prepare("SELECT id FROM events WHERE slug=? AND status='on_sale' LIMIT 1");
$st->execute([$slug]);
$event = $st->fetch(PDO::FETCH_ASSOC);
if (!$event) { echo json_encode(['ok'=>false,'err'=>'event']); exit; }
$event_id = $event['id'];

// Normalizace vstupů
$seats = array_values(array_unique(array_filter(array_map('strval', (array)$seats))));
$gaReq = [];
foreach ((array)$ga as $row) {
  $tid = isset($row['type_id']) ? (int)$row['type_id'] : 0;
  $qty = isset($row['qty']) ? (int)$row['qty'] : 0;
  if ($tid > 0 && $qty > 0) {
    if (!isset($gaReq[$tid])) $gaReq[$tid] = 0;
    $gaReq[$tid] += $qty;
  }
}

// Transakce
db()->beginTransaction();
try {
  // 2) Uvolni expirované holdy (sedadla + GA)
  $relSeats = db()->prepare("
    UPDATE seats_runtime
       SET state='free', hold_until=NULL, holder_sid=NULL
     WHERE event_id=? AND state='held' AND hold_until < NOW()
  ");
  $relSeats->execute([$event_id]);

  $relGA = db()->prepare("
    DELETE FROM event_ticket_type_holds
     WHERE event_id=? AND hold_until < NOW()
  ");
  $relGA->execute([$event_id]);

  // 3) Zamkni sedadla (pokud nějaká jsou)
  if (!empty($seats)) {
    // select-for-update + update na free -> held
    $get = db()->prepare("SELECT seat_id, state FROM seats_runtime WHERE event_id=? AND seat_id=? FOR UPDATE");
    $upd = db()->prepare("
      UPDATE seats_runtime
         SET state='held', hold_until=?, holder_sid=?
       WHERE event_id=? AND seat_id=? AND state='free'
    ");

    $ttl = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');

    foreach ($seats as $sidSeat) {
      $get->execute([$event_id, $sidSeat]);
      $row = $get->fetch(PDO::FETCH_ASSOC);
      if (!$row || $row['state'] !== 'free') {
        throw new RuntimeException('seat_unavailable');
      }
      // pokus o přepnutí na held
      $upd->execute([$ttl, $sid, $event_id, $sidSeat]);
      if ($upd->rowCount() !== 1) {
        throw new RuntimeException('seat_unavailable');
      }
    }
  }

  // 4) Zamkni GA typy (pokud nějaké jsou)
  if (!empty($gaReq)) {
    // Budeme držet na 15 minut, stejně jako sedadla
    $ttl = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');

    // dotazy
    $qType  = db()->prepare("SELECT id, capacity, sold FROM event_ticket_types WHERE event_id=? AND id=? FOR UPDATE");
    $qHeld  = db()->prepare("
      SELECT COALESCE(SUM(qty),0) AS held
        FROM event_ticket_type_holds
       WHERE event_id=? AND type_id=? AND hold_until > NOW()
    ");
    $insHold = db()->prepare("
      INSERT INTO event_ticket_type_holds (event_id, type_id, sid, qty, hold_until)
      VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($gaReq as $typeId => $qtyWanted) {
      if ($qtyWanted <= 0) continue;

      // lock řádku typu
      $qType->execute([$event_id, $typeId]);
      $type = $qType->fetch(PDO::FETCH_ASSOC);
      if (!$type) {
        throw new RuntimeException('ga_type_missing');
      }

      $capacity = (int)$type['capacity'];
      $sold     = (int)$type['sold'];

      // kolik je aktuálně držených (neexpirov.)
      $qHeld->execute([$event_id, $typeId]);
      $held = (int)($qHeld->fetchColumn() ?: 0);

      $remaining = max(0, $capacity - $sold - $held);
      if ($qtyWanted > $remaining) {
        throw new RuntimeException('ga_unavailable');
      }

      // založ hold pro aktuální session
      $insHold->execute([$event_id, $typeId, $sid, $qtyWanted, $ttl]);
    }
  }

  db()->commit();

  echo json_encode([
    'ok'          => true,
    'held_until'  => (new DateTimeImmutable('+15 minutes'))->format('c'),
    'session'     => $sid,        // pro navazující checkout (pokud se ti hodí)
  ]);
} catch (Throwable $e) {
  db()->rollBack();
  $code = $e->getMessage();
  // sjednocené chybové kódy pro frontend
  $err = in_array($code, ['seat_unavailable','ga_unavailable','ga_type_missing'], true)
    ? $code
    : 'unknown';
  echo json_encode(['ok'=>false, 'err'=>$err]);
}
