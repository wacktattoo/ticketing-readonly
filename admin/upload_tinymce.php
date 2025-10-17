<?php
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';
require_once __DIR__.'/../inc/helpers.php';

// Povolit jen přihlášenému adminovi
ensure_admin();

// TinyMCE posílá soubor pod klíčem "file"
if (empty($_FILES['file'])) {
  http_response_code(400);
  echo json_encode(['error' => 'No file']); exit;
}

// Ulož obrázek (používá tvou funkci save_uploaded_image)
$path = save_uploaded_image($_FILES['file']);
if (!$path) {
  http_response_code(400);
  echo json_encode(['error' => 'Upload failed']); exit;
}

// TinyMCE očekává JSON: { "location": "URL" }
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['location' => $path], JSON_UNESCAPED_SLASHES);
