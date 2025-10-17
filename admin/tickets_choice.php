<?php
// /admin/tickets_choice.php
declare(strict_types=1);
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
ensure_admin();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$eventId = $_GET['event'] ?? $_GET['id'] ?? '';
if ($eventId === '') { http_response_code(400); echo 'Chybí parametr event.'; exit; }

// Načti akci (kvůli titulu a ověření existence)
$st = db()->prepare("SELECT id, title, seating_mode, selling_mode FROM events WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', ''))) LIMIT 1");
$st->execute([$eventId, $eventId]);
$event = $st->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); echo 'Akce nenalezena.'; exit; }

// Volitelně: POST přepnutí režimu + redirect
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $go = $_POST['go'] ?? '';
  if ($go === 'seatmap' || $go === 'ga') {
    try {
      $upd = db()->prepare("
        UPDATE events SET seating_mode = ? 
        WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', ''))) LIMIT 1
      ");
      $upd->execute([$go, $eventId, $eventId]);
    } catch (Throwable $e) { /* ticho – přesměruj i tak */ }

    if ($go === 'seatmap') {
      header('Location: /admin/seatmap.php?event=' . urlencode((string)$event['id']));
    } else {
      header('Location: /admin/tickets_ga.php?event=' . urlencode((string)$event['id']));
    }
    exit;
  }
}

$current = strtolower((string)($event['seating_mode'] ?? ''));
if ($current !== 'ga' && $current !== 'seatmap') $current = '';

$admin_event_title = $event['title'] ?? '';
$admin_back_href   = '/admin/event_detail.php?id='.(isset($event['id'])?rawurlencode((string)$event['id']):'');
// volitelné: $admin_show_back = true; // jinak se ukáže automaticky, když je title
include __DIR__.'/_header.php';
?>
<style>
:root{ --bg:#f7f8fb; --panel:#fff; --text:#0b1220; --muted:#5b677a; --border:#e6e9ef; --accent:#2563eb; --accent-600:#1d4ed8; --radius:14px; --shadow:0 8px 30px rgba(3,14,38,.06) }
.wrap{max-width:900px;margin:0 auto;padding:22px 16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width:760px){ .grid{grid-template-columns:1fr} }
.card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px;box-shadow:var(--shadow)}
.h{margin:0 0 6px}
.p{margin:0;color:#556;line-height:1.5}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;border:1px solid var(--accent);background:var(--accent);color:#fff;font-weight:700;text-decoration:none}
.btn:hover{background:var(--accent-600)}
.btn.ghost{background:#fff;color:var(--accent)}
.badge{display:inline-flex;gap:6px;align-items:center;padding:4px 10px;border-radius:999px;border:1px solid var(--border);background:#f8fafc;color:#334155;font-weight:600}
</style>

<div class="wrap">
  <h1 style="text-align:center; margin:0 0 30px">Vstupenky &amp; sedadla</h1>

  <div class="grid">
    <section class="card">
      <h3 class="h">Prodej na místa</h3>
      <p class="p">Plán sedadel, řady a sektory. Prodej probíhá výběrem konkrétních míst.</p>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="go" value="seatmap">
        <button class="btn" type="submit"><i class="fa-solid fa-chair"></i> Spravovat prodej na místa</button>
      </form>
    </section>

    <section class="card">
      <h3 class="h">Volné vstupenky</h3>
      <p class="p">Správa typů vstupenek bez určených míst (cena, barva, kapacita). Prodej na „stání“/obecné sezení.</p>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="go" value="ga">
        <button class="btn" type="submit"><i class="fa-solid fa-ticket"></i> Spravovat vstupenky</button>
      </form>
    </section>
  </div>
</div>

<?php include __DIR__ . '/_footer.php'; ?>
