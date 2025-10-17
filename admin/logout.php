<?php
require_once __DIR__.'/../inc/auth.php';
admin_logout();
header('Location: /admin/login.php'); exit;
