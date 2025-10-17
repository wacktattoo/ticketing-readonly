<?php
function save_uploaded_image(array $file): ?string {
  if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mime = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
  // povolené typy
  $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp'];
  if (!isset($allowed[$mime])) return null;

  $subdir = '/uploads/'.date('Y').'/'.date('m');
  $targetDir = $_SERVER['DOCUMENT_ROOT'].$subdir;
  if (!is_dir($targetDir)) mkdir($targetDir, 0775, true);

  $name = bin2hex(random_bytes(8)).$allowed[$mime];
  $path = $targetDir.'/'.$name;
  if (!move_uploaded_file($file['tmp_name'], $path)) return null;

  return $subdir.'/'.$name; // veřejná URL cesta
}

function slugify($text) {
  $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
  $text = preg_replace('~[^\\pL\\d]+~u', '-', $text);
  $text = trim($text, '-');
  $text = strtolower($text);
  $text = preg_replace('~[^-a-z0-9]+~', '', $text);
  return $text ?: 'akce';
}


