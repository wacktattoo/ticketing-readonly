<?php
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';
ensure_admin();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// CSRF
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf'] ?? '', $csrf)) {
  http_response_code(400);
  die('Neplatný požadavek (CSRF).');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
  header('Location: /admin/?err=bad_id'); exit;
}

$db = db();
// pro jistotu zapni výjimky
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
  $db->beginTransaction();

  // --- smaž známé závislosti (uprav/rozšiř dle schématu) ---
  $db->prepare("DELETE FROM seats_runtime WHERE event_id = ?")->execute([$id]);
  $db->prepare("DELETE FROM event_seatmaps WHERE event_id = ?")->execute([$id]);

  // pokud máš další FK (příklady – odkomentuj, pokud existují):
  // $db->prepare("DELETE FROM tickets WHERE event_id = ?")->execute([$id]);
  // $db->prepare("DELETE FROM orders WHERE event_id = ?")->execute([$id]);

  // --- samotné smazání akce ---
  $stmt = $db->prepare("DELETE FROM events WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);

  // bezpečnost: ověř, že se opravdu něco smazalo
  if ($stmt->rowCount() < 1) {
    // nic se nesmazalo (špatné ID, nebo už smazané)
    $db->rollBack();
    header('Location: /admin/?err=not_deleted'); exit;
  }

  $db->commit();
  header('Location: /admin/?deleted=1'); exit;

} catch (PDOException $e) {
  // FK violation -> SQLSTATE 23000
  if ($e->getCode() === '23000') {
    try {
      // fallback: jen archivuj (soft delete), ať se UI pohne
      if ($db->inTransaction()) $db->rollBack();
      $upd = $db->prepare("UPDATE events SET status='archived' WHERE id = ? LIMIT 1");
      $upd->execute([$id]);
      header('Location: /admin/?archived=1'); exit;
    } catch (Throwable $e2) {
      if ($db->inTransaction()) $db->rollBack();
      header('Location: /admin/?err=fk_block'); exit;
    }
  } else {
    if ($db->inTransaction()) $db->rollBack();
    header('Location: /admin/?err=sql'); exit;
  }
} catch (Throwable $e) {
  if ($db->inTransaction()) $db->rollBack();
  header('Location: /admin/?err=unknown'); exit;
}
