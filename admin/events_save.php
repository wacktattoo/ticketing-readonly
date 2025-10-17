// 1) Rozhodnutí GA vs. seatmapa
$ga_enabled = !empty($_POST['ga_enabled']);
if ($ga_enabled) {
  // volitelně: pokud má akce seatmapu, můžeš ji „vyprázdnit“
  $q = db()->prepare("UPDATE event_seatmaps SET schema_json=JSON_SET(COALESCE(schema_json,'{}'),
      '$.seats', JSON_ARRAY(),
      '$.tables', JSON_ARRAY(),
      '$.tiers', JSON_OBJECT(),
      '$.rows', JSON_OBJECT()
    ) WHERE event_id=?");
  $q->execute([$event_id]);
}
// Režim prodeje: seats | ga | mixed
$mode = $_POST['selling_mode'] ?? 'mixed';
if (!in_array($mode, ['seats','ga','mixed'], true)) { $mode = 'mixed'; }

$upd = db()->prepare("UPDATE events SET selling_mode=? WHERE id=?");
$upd->execute([$mode, $event_id]);

// (VOLITELNÉ) “vynutit GA jen” – vyprázdnit seatmapu, ale jen když to chceš a dáš k tomu checkbox potvrzení
$purge = !empty($_POST['purge_seatmap']); // např. potvrzovací checkbox v editaci akce
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
  $q->execute([$event_id]);
}
// 2) CRUD pro GA typy
$ttData = $_POST['tt'] ?? [];            // pole tt[id][field]
$toDel  = array_filter(array_map('intval', explode(',', $_POST['tt_delete_ids'] ?? '')));

db()->beginTransaction();
try {
  // smazání
  if ($toDel) {
    // bezpečnost: nesmažím type s prodanými (>0). Můžeš udělat soft-delete flag
    $in = implode(',', array_fill(0, count($toDel), '?'));
    $chk = db()->prepare("SELECT id, sold FROM event_ticket_types WHERE event_id=? AND id IN ($in)");
    $chk->execute(array_merge([$event_id], $toDel));
    foreach ($chk as $r) {
      if ((int)$r['sold'] > 0) throw new Exception('Nelze smazat typ s prodanými kusy.');
    }
    $del = db()->prepare("DELETE FROM event_ticket_types WHERE event_id=? AND id IN ($in)");
    $del->execute(array_merge([$event_id], $toDel));
  }

  // upsert řádek po řádku
  foreach ($ttData as $id => $row) {
    $code = trim($row['code'] ?? '');
    $name = trim($row['name'] ?? '');
    $czk  = (int)($row['czk'] ?? 0);
    $eur  = (int)($row['eur'] ?? 0);
    $col  = ($row['color'] ?? '#2563eb');
    $cap  = max(0, (int)($row['capacity'] ?? 0));

    if ($code === '' || $name === '') continue;

    $prices = json_encode(['CZK'=>$czk, 'EUR'=>$eur], JSON_UNESCAPED_UNICODE);

    if ((int)$id > 0) {
      // UPDATE
      $u = db()->prepare("UPDATE event_ticket_types
                          SET code=?, name=?, prices_json=?, color=?, capacity=?
                          WHERE id=? AND event_id=?");
      $u->execute([$code, $name, $prices, $col, $cap, (int)$id, $event_id]);
    } else {
      // INSERT
      $ins = db()->prepare("INSERT INTO event_ticket_types (event_id, code, name, prices_json, color, capacity)
                            VALUES (?, ?, ?, ?, ?, ?)");
      $ins->execute([$event_id, $code, $name, $prices, $col, $cap]);
    }
  }

  db()->commit();
  // redirect zpět na edit
} catch (Throwable $e) {
  db()->rollBack();
  // ukaž chybu v adminu
  die('Chyba ukládání GA typů: '.e($e->getMessage()));
}
