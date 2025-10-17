<?php
// admin/dashboard.php — Nástěnka pro Šlágr Ticket Admin
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
ensure_admin();

// ====== Časové rozsahy ======
$tz = new DateTimeZone('Europe/Prague');
$todayStart = (new DateTime('today', $tz))->format('Y-m-d 00:00:00');
$now        = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
$days7Start = (new DateTime('-6 days', $tz))->format('Y-m-d 00:00:00'); // posledních 7 dní včetně dneška
$days14Start= (new DateTime('-13 days', $tz))->format('Y-m-d 00:00:00');
$days30Start= (new DateTime('-29 days', $tz))->format('Y-m-d 00:00:00');

// Helper na bezpečné dotazy
function db_scalar($sql, $params = []) {
  try { $x = db()->prepare($sql); $x->execute($params); return (float)$x->fetchColumn(); } catch (Throwable $e) { return 0; }
}
function db_rows($sql, $params = []) {
  try { $x = db()->prepare($sql); $x->execute($params); return $x->fetchAll(); } catch (Throwable $e) { return []; }
}

// ====== Agregace tržeb a objednávek ======
$revenueToday = db_scalar("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$todayStart, $now]);
$revenue7d    = db_scalar("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$days7Start, $now]);
$revenue30d   = db_scalar("SELECT COALESCE(SUM(amount),0) FROM orders WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$days30Start, $now]);

$ordersToday  = db_scalar("SELECT COUNT(*) FROM orders WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$todayStart, $now]);
$orders7d     = db_scalar("SELECT COUNT(*) FROM orders WHERE status='paid' AND paid_at BETWEEN ? AND ?", [$days7Start, $now]);

// Denní tržby za posledních 14 dní
$dailyData = db_rows("SELECT DATE(paid_at) d, SUM(amount) s FROM orders WHERE status='paid' AND paid_at BETWEEN ? AND ? GROUP BY DATE(paid_at) ORDER BY d ASC", [$days14Start, $now]);
$labels14 = [];$values14=[];
$cursor = new DateTime($days14Start, $tz);
$end    = new DateTime('today', $tz);
while ($cursor <= $end) { $labels14[]=$cursor->format('j.n.'); $values14[$cursor->format('Y-m-d')]=0; $cursor->modify('+1 day'); }
foreach ($dailyData as $row) { $values14[$row['d']] = (float)$row['s']; }
$chartValues = array_values($values14);

// Stav sedadel (pokud existuje tabulka tickets se sloupci status)
$ticketStatus = db_rows("SELECT status, COUNT(*) c FROM tickets GROUP BY status ORDER BY c DESC");

// Nejbližší akce a kapacity
$upcoming = db_rows("SELECT id, title, slug, venue_name, starts_at, status, total_capacity, sold_count, (total_capacity - sold_count) AS remaining FROM events WHERE status IN ('on_sale','sold_out') AND starts_at >= NOW() ORDER BY starts_at ASC LIMIT 8");

// Akce s nízkou dostupností
$lowStock = db_rows("SELECT id, title, slug, starts_at, total_capacity, sold_count, (total_capacity - sold_count) remaining FROM events WHERE status='on_sale' AND (total_capacity - sold_count) <= GREATEST(ROUND(total_capacity*0.1), 25) ORDER BY starts_at ASC LIMIT 6");

// Top akce podle tržeb za 30 dní
$topEvents = db_rows("SELECT e.id, e.title, e.slug, e.starts_at, SUM(o.amount) sum_amount, COUNT(o.id) orders FROM orders o JOIN events e ON e.id=o.event_id WHERE o.status='paid' AND o.paid_at BETWEEN ? AND ? GROUP BY e.id, e.title, e.slug, e.starts_at ORDER BY sum_amount DESC LIMIT 5", [$days30Start, $now]);

// Poslední platby
$lastOrders = db_rows("SELECT id, buyer_name, buyer_email, amount, currency, status, paid_at FROM orders ORDER BY created_at DESC LIMIT 6");

// Pomocné formátování
function moneyCZ($v) { return number_format((float)$v, 0, ',', ' ') . ' Kč'; }
function dt($s) { return $s ? (new DateTime($s))->format('j. n. Y, H:i') : '—'; }
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Šlágr Ticket Admin — Nástěnka</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-" crossorigin="anonymous" referrerpolicy="no-referrer" />
  <style>
    :root{ --blue:#2563eb; --bg:#0f172a; --panel:#111827; --muted:#6b7280; --ring:#1f2a44; --ok:#10b981; --warn:#f59e0b; --danger:#ef4444; }
    *{box-sizing:border-box}
    body{margin:0; background:#0b1220; color:#e5e7eb; font-family:Inter,system-ui,Segoe UI,Roboto,Arial,sans-serif}
    a{color:inherit; text-decoration:none}
    .wrap{max-width:1180px; margin:0 auto; padding:24px}
    header{display:flex; align-items:center; justify-content:space-between; margin-bottom:18px}
    h1{font-size:28px; font-weight:700; letter-spacing:.2px; margin:0}
    .sub{color:#94a3b8; font-size:14px}
    .backbar{margin:10px 0 18px}
    .btn{display:inline-flex; align-items:center; gap:8px; padding:9px 14px; background:var(--blue); border:1px solid rgba(37,99,235,.5); color:#fff; border-radius:12px; font-weight:600; transition:.2s}
    .btn:hover{filter:brightness(1.08)}
    .btn.secondary{background:#0b1530; border-color:#1f2a44; color:#cbd5e1}

    .grid{display:grid; gap:16px}
    .grid.kpis{grid-template-columns:repeat(4,minmax(0,1fr))}
    .grid.two{grid-template-columns:2fr 1fr}
    .grid.three{grid-template-columns:1.2fr 1fr 1fr}
    @media (max-width:1100px){.grid.kpis{grid-template-columns:repeat(2,1fr)} .grid.two,.grid.three{grid-template-columns:1fr}}

    .card{background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,.01)); border:1px solid #1f2a44; border-radius:16px; padding:16px; position:relative; overflow:hidden}
    .card .title{font-weight:600; font-size:13px; color:#9ca3af; text-transform:uppercase; letter-spacing:.8px; margin-bottom:8px}
    .kpi{display:flex; align-items:flex-end; justify-content:space-between}
    .kpi .value{font-size:28px; font-weight:800}
    .kpi .trend{font-size:12px; padding:4px 8px; border-radius:999px}
    .trend.up{background:rgba(16,185,129,.12); color:#34d399; border:1px solid rgba(16,185,129,.25)}
    .trend.down{background:rgba(239,68,68,.12); color:#f87171; border:1px solid rgba(239,68,68,.25)}

    .table{width:100%; border-collapse:separate; border-spacing:0 8px}
    .table th{font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:.6px; font-weight:700; text-align:left; padding:0 12px 6px}
    .table td{background:#0b1530; border:1px solid #1f2a44; padding:10px 12px; vertical-align:middle}
    .table tr td:first-child{border-top-left-radius:10px; border-bottom-left-radius:10px}
    .table tr td:last-child{border-top-right-radius:10px; border-bottom-right-radius:10px}

    .chip{display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:12px; border:1px solid #1f2a44; background:#0b1530}
    .chip .dot{width:8px; height:8px; border-radius:999px; background:#64748b}
    .chip.on_sale .dot{background:var(--ok)}
    .chip.sold_out .dot{background:#8b5cf6}
    .chip.archived .dot{background:#64748b}

    .badge{display:inline-flex; align-items:center; gap:6px; padding:4px 8px; border-radius:8px; font-size:11px; border:1px solid #1f2a44; background:#0b1530; color:#cbd5e1}
    .badge.warn{border-color:rgba(245,158,11,.3); color:#fbbf24}
    .badge.danger{border-color:rgba(239,68,68,.3); color:#f87171}

    .row{display:flex; gap:10px; flex-wrap:wrap; align-items:center}
    .spacer{height:10px}
    .muted{color:#94a3b8}
    .mono{font-variant-numeric:tabular-nums}
    .right{margin-left:auto}

    .quickbar{display:flex; gap:10px; flex-wrap:wrap}
    .quickbar .btn{background:#0b1530}

    .search{position:relative}
    .search input{background:#0b1530; border:1px solid #1f2a44; color:#e5e7eb; padding:10px 12px 10px 36px; border-radius:12px; outline:none; width:260px}
    .search i{position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#64748b}

    /* mini cards */
    .mini-list{display:grid; gap:8px}
    .mini{display:flex; align-items:center; gap:12px; background:#0b1530; border:1px solid #1f2a44; padding:10px 12px; border-radius:12px}
    .mini .dot{width:10px; height:10px; border-radius:999px}

    /* Chart container */
    .chart-wrap{height:260px}
  </style>
</head>
<body>
  <div class="wrap">
    <header>
      <div>
        <h1>Nástěnka</h1>
        <div class="sub">Rychlý přehled prodejů, událostí a stavu systému</div>
      </div>
      <div class="row">
        <div class="search"><i class="fa fa-search"></i><input id="q" type="search" placeholder="Hledat objednávky, akce…" onkeydown="if(event.key==='Enter'){window.location='orders.php?q='+encodeURIComponent(this.value)}"></div>
        <a class="btn" href="event_new.php"><i class="fa fa-plus"></i> Nová akce</a>
      </div>
    </header>

    <div class="backbar">
      <a class="btn secondary" href="events.php"><i class="fa fa-arrow-left"></i> Zpět na přehled akcí</a>
    </div>

    <!-- KPI bloky -->
    <section class="grid kpis">
      <div class="card">
        <div class="title">Tržby dnes</div>
        <div class="kpi">
          <div class="value mono"><?php echo moneyCZ($revenueToday); ?></div>
          <span class="trend up"><?php echo $ordersToday; ?> objednávek</span>
        </div>
      </div>
      <div class="card">
        <div class="title">Tržby posledních 7 dní</div>
        <div class="kpi">
          <div class="value mono"><?php echo moneyCZ($revenue7d); ?></div>
          <span class="trend up"><?php echo $orders7d; ?> objednávek</span>
        </div>
      </div>
      <div class="card">
        <div class="title">Tržby posledních 30 dní</div>
        <div class="kpi">
          <div class="value mono"><?php echo moneyCZ($revenue30d); ?></div>
          <span class="trend up">rolling 30</span>
        </div>
      </div>
      <div class="card">
        <div class="title">Sklad / kapacity</div>
        <div class="kpi">
          <div class="value mono"><?php echo count($upcoming); ?> blízkých akcí</div>
          <span class="trend <?php echo count($lowStock)?'down':'up'; ?>"><?php echo count($lowStock) ? count($lowStock).' nízká dostupnost' : 'OK'; ?></span>
        </div>
      </div>
    </section>

    <div class="spacer"></div>

    <section class="grid two">
      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:8px">
          <div class="title">Denní tržby (14 dní)</div>
          <div class="row">
            <a class="badge" href="orders.php?range=14d"><i class="fa fa-arrow-up-right-from-square"></i> Detail</a>
          </div>
        </div>
        <div class="chart-wrap"><canvas id="chart14"></canvas></div>
      </div>
      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:8px">
          <div class="title">Distribuce sedadel</div>
          <div class="row">
            <a class="badge" href="tickets.php"><i class="fa fa-chair"></i> Přesunout</a>
          </div>
        </div>
        <div class="chart-wrap"><canvas id="chartSeats"></canvas></div>
      </div>
    </section>

    <div class="spacer"></div>

    <section class="grid three">
      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:6px">
          <div class="title">Nejbližší akce</div>
          <div class="quickbar">
            <a class="badge" href="events.php?status=on_sale"><span class="dot" style="background:var(--ok)"></span> On sale</a>
            <a class="badge" href="events.php?status=sold_out"><span class="dot" style="background:#8b5cf6"></span> Sold out</a>
            <a class="badge" href="events.php?status=archived"><span class="dot" style="background:#64748b"></span> Archivované</a>
          </div>
        </div>
        <table class="table">
          <thead><tr><th>Akce</th><th>Start</th><th>Stav</th><th class="right">Zbývá</th></tr></thead>
          <tbody>
            <?php if(!$upcoming): ?>
              <tr><td colspan="4" class="muted" style="padding:12px">Žádné nadcházející akce.</td></tr>
            <?php endif; ?>
            <?php foreach($upcoming as $e): ?>
              <tr>
                <td><a href="event_detail.php?id=<?php echo (int)$e['id']; ?>"><strong><?php echo htmlspecialchars($e['title']); ?></strong></a><div class="muted"><?php echo htmlspecialchars($e['venue_name']); ?></div></td>
                <td class="mono"><?php echo dt($e['starts_at']); ?></td>
                <td><span class="chip <?php echo htmlspecialchars($e['status']); ?>"><span class="dot"></span> <?php echo htmlspecialchars($e['status']); ?></span></td>
                <td class="right mono"><?php echo max(0,(int)$e['remaining']); ?> / <?php echo (int)$e['total_capacity']; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:6px">
          <div class="title">Nízká dostupnost</div>
          <a class="badge warn" href="events.php?filter=low"><i class="fa fa-bell"></i> Upozornit</a>
        </div>
        <div class="mini-list">
          <?php if(!$lowStock): ?>
            <div class="muted">Vše v pořádku.</div>
          <?php endif; ?>
          <?php foreach($lowStock as $e): $pct = $e['total_capacity']>0 ? round(($e['remaining']/$e['total_capacity'])*100) : 0; ?>
            <div class="mini">
              <span class="dot" style="background:<?php echo $pct<=5?'var(--danger)':($pct<=10?'var(--warn)':'var(--ok)'); ?>"></span>
              <div style="flex:1">
                <div><a href="event_detail.php?id=<?php echo (int)$e['id']; ?>"><strong><?php echo htmlspecialchars($e['title']); ?></strong></a></div>
                <div class="muted">Zbývá <?php echo (int)$e['remaining']; ?> z <?php echo (int)$e['total_capacity']; ?> (<?php echo $pct; ?>%)</div>
              </div>
              <a class="badge" href="tickets.php?event_id=<?php echo (int)$e['id']; ?>">Správa</a>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:6px">
          <div class="title">Top akce (30 dní)</div>
          <a class="badge" href="orders.php?range=30d"><i class="fa fa-arrow-up-right-from-square"></i> Objednávky</a>
        </div>
        <table class="table">
          <thead><tr><th>Akce</th><th>Objednávky</th><th class="right">Tržby</th></tr></thead>
          <tbody>
            <?php if(!$topEvents): ?>
              <tr><td colspan="3" class="muted" style="padding:12px">Žádná data.</td></tr>
            <?php endif; ?>
            <?php foreach($topEvents as $t): ?>
              <tr>
                <td><a href="event_detail.php?id=<?php echo (int)$t['id']; ?>"><strong><?php echo htmlspecialchars($t['title']); ?></strong></a><div class="muted">Start: <?php echo dt($t['starts_at']); ?></div></td>
                <td class="mono"><?php echo (int)$t['orders']; ?></td>
                <td class="right mono"><?php echo moneyCZ($t['sum_amount']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div class="spacer"></div>

    <section class="grid two">
      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:6px">
          <div class="title">Poslední objednávky</div>
          <div class="row">
            <a class="badge" href="orders.php"><i class="fa fa-receipt"></i> Všechny objednávky</a>
          </div>
        </div>
        <table class="table">
          <thead><tr><th>#</th><th>Zákazník</th><th>Stav</th><th>Zaplaceno</th><th class="right">Částka</th></tr></thead>
          <tbody>
          <?php if(!$lastOrders): ?>
            <tr><td colspan="5" class="muted" style="padding:12px">Zatím nic nového.</td></tr>
          <?php endif; ?>
          <?php foreach($lastOrders as $o): ?>
            <tr>
              <td class="mono">#<?php echo (int)$o['id']; ?></td>
              <td>
                <div><strong><?php echo htmlspecialchars($o['buyer_name'] ?: '—'); ?></strong></div>
                <div class="muted"><?php echo htmlspecialchars($o['buyer_email'] ?: ''); ?></div>
              </td>
              <td>
                <?php if($o['status']==='paid'): ?>
                  <span class="chip on_sale"><span class="dot"></span> paid</span>
                <?php elseif($o['status']==='pending'): ?>
                  <span class="chip"><span class="dot" style="background:var(--warn)"></span> pending</span>
                <?php else: ?>
                  <span class="chip"><span class="dot"></span> <?php echo htmlspecialchars($o['status']); ?></span>
                <?php endif; ?>
              </td>
              <td class="mono"><?php echo dt($o['paid_at']); ?></td>
              <td class="right mono"><?php echo moneyCZ($o['amount']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:6px">
          <div class="title">Rychlé akce</div>
        </div>
        <div class="quickbar">
          <a class="btn" href="event_new.php"><i class="fa fa-plus"></i> Vytvořit akci</a>
          <a class="btn" href="orders_export.php"><i class="fa fa-file-export"></i> Export objednávek</a>
          <a class="btn" href="payments.php"><i class="fa fa-money-bill"></i> Platby</a>
          <a class="btn" href="settings.php"><i class="fa fa-gear"></i> Nastavení</a>
        </div>
        <div class="spacer"></div>
        <div class="title" style="margin-top:8px">Upozornění systému</div>
        <div class="mini-list">
          <?php if(count($lowStock)): ?>
            <div class="mini"><span class="dot" style="background:var(--warn)"></span><div>Máte <strong><?php echo count($lowStock); ?></strong> akcí s nízkou dostupností. Zvažte zvýraznění nebo navýšení kapacity.</div><a class="badge warn" href="events.php?filter=low">Zobrazit</a></div>
          <?php endif; ?>
          <div class="mini"><span class="dot" style="background:var(--ok)"></span><div>Stripe/platby: bez známých problémů.</div><a class="badge" href="payments.php">Detail</a></div>
          <div class="mini"><span class="dot" style="background:#64748b"></span><div>Tip: Přidejte popis a obrázek ke všem novým akcím kvůli SEO a CTR.</div></div>
        </div>
      </div>
    </section>

    <div class="spacer"></div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script>
    // Data z PHP
    const labels14 = <?php echo json_encode($labels14, JSON_UNESCAPED_UNICODE); ?>;
    const values14 = <?php echo json_encode(array_map('floatval',$chartValues)); ?>;
    const seatData = <?php echo json_encode(array_map(function($r){return ['status'=>$r['status'],'c'=>(int)$r['c']];}, $ticketStatus)); ?>;

    // Line/bar chart – posledních 14 dní
    const ctx1 = document.getElementById('chart14');
    if (ctx1) {
      new Chart(ctx1, {
        type: 'bar',
        data: { labels: labels14, datasets: [{ label: 'Tržby', data: values14 }] },
        options: {
          responsive:true,
          maintainAspectRatio:false,
          scales: { y: { beginAtZero:true, grid:{color:'rgba(148,163,184,.15)'} }, x:{ grid:{display:false}} },
          plugins:{ legend:{display:false} }
        }
      });
    }

    // Doughnut – stav sedadel
    const ctx2 = document.getElementById('chartSeats');
    if (ctx2 && seatData && seatData.length) {
      new Chart(ctx2, {
        type:'doughnut',
        data:{ labels: seatData.map(s=>s.status), datasets:[{ data: seatData.map(s=>s.c) }] },
        options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{position:'bottom'}} }
      });
    }
  </script>
</body>
</html>
