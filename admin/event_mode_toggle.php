<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
ensure_admin();

$id = $_POST['id'] ?? '';
$to = $_POST['to'] ?? '';
if ($id==='' || !in_array($to,['ga','seatmap'],true)) { http_response_code(400); exit('Bad request'); }

$st = db()->prepare("
  UPDATE events SET seating_mode = ?
  WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', '')))
  LIMIT 1
");
$st->execute([$to, $id, $id]);

header('Location: /admin/event_detail.php?id='.urlencode($id));
exit;
