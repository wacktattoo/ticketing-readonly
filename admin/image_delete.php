<?php
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';
ensure_admin();

$id = (int)($_GET['id'] ?? 0);
$event = $_GET['event'] ?? '';

if ($id>0) {
  $st = db()->prepare("SELECT url FROM event_images WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $row = $st->fetch();
  if ($row) {
    // smaž soubor (pokud chceš)
    $path = $_SERVER['DOCUMENT_ROOT'].$row['url'];
    if (is_file($path)) @unlink($path);
    db()->prepare("DELETE FROM event_images WHERE id=?")->execute([$id]);
  }
}
header('Location: /admin/seatmap.php?event='.$event);
