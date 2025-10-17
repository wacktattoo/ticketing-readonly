<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root{
      --bg:#f5f7fb; --text:#0b1220; --muted:#5b677a; --border:#e7ecf5;
      --blue:#2563eb; --blue-600:#1d4ed8; --blue-50:#eff6ff; --radius:12px;
    }
    body{ margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto; background:var(--bg); color:var(--text); }
/* Klikatelné prvky = ručička */
button,
.btn,
.back-btn,
.btn-ghost,
.btn-action,
.btn-delete,
.btn-copy-link,
.btn-zobrazit,
.admin-link,
a[href],
[role="button"] {
  cursor: pointer;
}

/* Výjimka: disabled */
button[disabled],
.btn[aria-disabled="true"],
[aria-disabled="true"] {
  cursor: not-allowed;
}
main.container{ max-width:1100px; margin:22px auto; padding:0 14px; }
.current-title{ font-weight:600; color:#0b1220; white-space:nowrap; max-width:38vw; overflow:hidden; text-overflow:ellipsis; }
  /* VŠECHNO je scoped na nav.admin-header – nic globálního */
  nav.admin-header{
    --hdr-text:#0b1220;
    --hdr-border:#e7ecf5;
    --hdr-blue:#2563eb;
    --hdr-blue-600:#1d4ed8;
    --hdr-blue-50:#eff6ff;

    position: sticky; top: 0; z-index: 50;
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    background: rgba(37,99,235,.08);
    color: var(--hdr-text);
    backdrop-filter: saturate(140%) blur(6px);
    border-bottom: 1px solid var(--hdr-border);
  }

  /* Klikatelné jen uvnitř headeru */
  nav.admin-header a,
  nav.admin-header button { cursor: pointer; }

  /* Brand jako odkaz na /admin/ */
  nav.admin-header .admin-brand-link{
    display:flex; align-items:center; gap:10px;
    padding-right:6px; margin-right:4px;
    text-decoration:none; color:inherit;
  }
  nav.admin-header .admin-brand-link .logo{
    width:28px;height:28px;border-radius:8px;
    background:var(--hdr-blue);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:700;font-size:14px;
    box-shadow:0 4px 14px rgba(37,99,235,.25);
    transition: transform .15s ease;
  }
  nav.admin-header .admin-brand-link .name{
    font-weight:600;letter-spacing:.2px;color:var(--hdr-text);
    transition: color .2s ease;
  }
  nav.admin-header .admin-brand-link:hover .logo{ transform:translateY(-1px); }
  nav.admin-header .admin-brand-link:hover .name{ color:var(--hdr-blue); }

  /* Primární navigace */
  nav.admin-header .admin-nav{ display:flex; align-items:center; gap:6px; overflow-x:auto; -webkit-overflow-scrolling:touch; }
  nav.admin-header .admin-link{
    color:inherit; text-decoration:none; font-weight:600;
    padding:8px 12px; border-radius:10px; white-space:nowrap;
    transition: background .2s ease, color .2s ease;
  }
  nav.admin-header .admin-link:hover{ background:var(--hdr-blue-50); color:var(--hdr-blue); }
  nav.admin-header .admin-link.active{ color:var(--hdr-blue); background:#fff; border:1px solid var(--hdr-border); }

  nav.admin-header .admin-spacer{ flex:1; }

  /* Zpět + titulek */
  nav.admin-header .admin-backwrap{ display:flex; align-items:center; gap:10px; margin-right:8px; }
  nav.admin-header .back-btn{
    display:inline-flex; align-items:center; gap:8px;
    font-weight:600; font-size:14px; text-decoration:none;
    color:var(--hdr-blue); border:1px solid var(--hdr-blue); background:#fff;
    padding:8px 12px; border-radius:10px;
    transition: background .2s, border-color .2s, transform .05s, color .2s;
  }
  nav.admin-header .back-btn:hover{ background:var(--hdr-blue-600); color:#fff; border-color:var(--hdr-blue-600); }
  nav.admin-header .back-btn:active{ transform:translateY(1px) }
  nav.admin-header .back-btn svg{ width:16px; height:16px; fill:currentColor }
  nav.admin-header .current-title{
    font-weight:600; color:var(--hdr-text);
    white-space:nowrap; max-width:38vw; overflow:hidden; text-overflow:ellipsis;
  }

  /* Pravá část */
  nav.admin-header .admin-right{ display:flex; align-items:center; gap:10px; }
  nav.admin-header .btn-ghost{
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 12px; border-radius:10px;
    color:inherit; text-decoration:none; border:1px solid transparent;
    transition: background .2s ease, color .2s ease, border-color .2s ease;
  }
  nav.admin-header .btn-ghost:hover{ background:#fff; border-color:var(--hdr-border); color:var(--hdr-blue); }
</style>

</head>
<body>
<?php
// helpery + proměnné z volající stránky
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
$__hdr_title    = isset($admin_event_title) ? trim((string)$admin_event_title) : '';
$__hdr_backHref = isset($admin_back_href) ? trim((string)$admin_back_href) : '';
$__hdr_showBack = isset($admin_show_back) ? (bool)$admin_show_back : ($__hdr_title !== '');
$__hdr_fallback = '/admin/';
?>
<nav class="admin-header">
  <a href="/admin/" class="admin-brand-link" aria-label="Přejít na přehled akcí v administraci">
    <div class="logo">ŠK</div>
    <div class="name">Šlágr koncerty admin</div>
  </a>

  <div class="admin-nav">
    <a href="/admin/" class="admin-link"><i class="fa-regular fa-bell"></i> Nástěnka</a>
    <a href="/admin/events.php" class="admin-link"><i class="fa-regular fa-calendar"></i> Akce</a>
    <a href="/admin/orders.php" class="admin-link"><i class="fa-regular fa-file-lines"></i> Objednávky</a>
    <a href="/admin/customers.php" class="admin-link"><i class="fa-regular fa-user"></i> Zákazníci</a>
    <a href="/admin/stats.php" class="admin-link"><i class="fa-solid fa-chart-line"></i> Statistiky</a>
  </div>

  <div class="admin-spacer"></div>

  <?php if ($__hdr_showBack): ?>
    <div class="admin-backwrap">
      <button class="back-btn" type="button"
              onclick="(history.length>1 ? history.back() : (location.href='<?=h($__hdr_backHref ?: $__hdr_fallback)?>'))"
              title="Zpět">
        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M12.7 15.3a1 1 0 0 1-1.4 0L6 10l5.3-5.3a1 1 0 1 1 1.4 1.4L8.41 10l4.3 4.3a1 1 0 0 1 0 1.4z"/></svg>
        Zpět
      </button>
      <?php if ($__hdr_title): ?>
        <div class="current-title"><?= h($__hdr_title) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="admin-right">
    <a href="/index.php" class="btn-ghost" target="_blank"><i class="fa-solid fa-house"></i> Zobrazit události</a>
    <a href="/admin/logout.php" class="btn-ghost"><i class="fa-solid fa-right-from-bracket"></i> Odhlásit</a>
  </div>
</nav>

<main class="container">

