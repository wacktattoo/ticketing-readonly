<?php
function ensure_admin() {
  if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php'); exit;
  }
}
function admin_login($email, $pass): bool {
  $stmt = db()->prepare("SELECT id, email, password_hash FROM admin_user WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch();
  if ($u && password_verify($pass, $u['password_hash'])) {
    $_SESSION['admin_id'] = $u['id'];
    $_SESSION['admin_email'] = $u['email'];
    return true;
  }
  return false;
}
function admin_logout() {
  session_destroy();
}
