<?php
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (admin_login($_POST['email'] ?? '', $_POST['password'] ?? '', !empty($_POST['remember']))) {
    header('Location: /admin/index.php'); exit;
  }
  $err = 'Neplatné přihlašovací údaje';
}
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin – Přihlášení</title>

<style>
:root{
  --bg:#f7f8fb; --panel:#ffffff; --text:#0b1220; --muted:#5b677a; --border:#e6e9ef;
  --accent:#2563eb; --accent-600:#1d4ed8; --radius:16px; --shadow:0 8px 30px rgba(3,14,38,.06);
}

*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0; background:var(--bg); color:var(--text);
  font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
  display:grid; place-items:center;
  padding:20px;
}

.auth-card{
  width:100%; max-width:420px;
  background:var(--panel);
  border:1px solid var(--border);
  border-radius:var(--radius);
  box-shadow:var(--shadow);
  padding:22px;
}

.header{
  display:flex; align-items:center; gap:12px; margin-bottom:6px;
}
.logo{
  width:38px; height:38px; border-radius:12px;
  display:grid; place-items:center;
  color:#fff; font-weight:800; letter-spacing:.5px;
  background:linear-gradient(135deg,#3b82f6, #2563eb);
  box-shadow:0 6px 18px rgba(37,99,235,.25);
}
h1{margin:0; font-size:20px; line-height:1.2}
.sub{margin:6px 0 14px; color:var(--muted); font-size:13px}

label{display:block; font-weight:600; color:#1f2937; margin:12px 0 6px}
.input{
  width:100%; padding:11px 12px;
  border:1px solid var(--border); border-radius:12px;
  background:#fff; font-size:14px; color:var(--text);
  transition:border-color .2s, box-shadow .2s;
}
.input:focus{
  outline:none; border-color:var(--accent);
  box-shadow:0 0 0 3px rgba(37,99,235,.15);
}

.row{
  display:flex; align-items:center; justify-content:space-between; gap:8px; margin-top:10px;
}
.checkbox{display:flex; align-items:center; gap:8px; color:#374151; font-size:14px}
.checkbox input{width:16px; height:16px; accent-color:var(--accent)}

.btn{
  width:100%;
  display:inline-flex; justify-content:center; align-items:center; gap:8px;
  margin-top:14px; padding:12px 14px;
  border-radius:12px; border:1px solid var(--accent);
  background:var(--accent); color:#fff; font-weight:700; cursor:pointer;
  transition:background .15s ease, border-color .15s ease, transform .05s ease, box-shadow .15s ease;
  box-shadow:0 10px 24px rgba(37, 100, 235, 0.12);
}
.btn:hover{ background:var(--accent-600); border-color:var(--accent-600) }
.btn:active{ transform:translateY(1px) }

.alert{
  margin:10px 0 8px;
  background:#fef2f2; color:#7f1d1d;
  border:1px solid #fecaca; border-radius:12px;
  padding:10px 12px; font-size:14px;
}

.helper{
  display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:8px;
  font-size:13px; color:#6b7280;
}
a.muted{color:#2563eb; text-decoration:none}
a.muted:hover{text-decoration:underline}

.pass-wrap{ position:relative }
.toggle-pass{
  position:absolute; right:10px; top:50%; transform:translateY(-50%);
  border:none; background:transparent; color:#4b5563; cursor:pointer; font-size:13px; padding:4px 6px;
}
.toggle-pass:hover{ color:#111827 }
</style>
</head>
<body>

<form method="post" class="auth-card" novalidate>
  <div class="header">
    <div class="logo">ŠK</div>
    <div>
      <h1>Admin přihlášení</h1>
      <div class="sub">Zadej své přístupové údaje</div>
    </div>
  </div>

  <?php if($err): ?>
    <div class="alert"><?= htmlspecialchars($err, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <label for="email">E-mail</label>
  <input class="input" type="email" id="email" name="email" required autofocus autocomplete="username">

  <label for="password">Heslo</label>
  <div class="pass-wrap">
    <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
    <button class="toggle-pass" type="button" aria-label="Zobrazit heslo">Zobrazit</button>
  </div>

  <div class="row">
    <label class="checkbox">
      <input type="checkbox" name="remember" value="1">
      <span>Zůstat přihlášen</span>
    </label>
    <!-- volitelně odkaz na reset, pokud máš -->
    <!-- <a class="muted" href="/admin/forgot.php">Zapomenuté heslo?</a> -->
  </div>

  <button class="btn" type="submit">Přihlásit</button>

  <div class="helper">
    <span>Potíže s přihlášením?</span>
    <a class="muted" href="/">Zpět na web</a>
  </div>
</form>

<script>
  // přepínání zobrazení hesla
  (function(){
    const btn = document.querySelector('.toggle-pass');
    const inp = document.getElementById('password');
    if(!btn || !inp) return;
    btn.addEventListener('click', ()=>{
      const isPwd = inp.type === 'password';
      inp.type = isPwd ? 'text' : 'password';
      btn.textContent = isPwd ? 'Skrýt' : 'Zobrazit';
      inp.focus();
    });
  })();
</script>

</body>
</html>
