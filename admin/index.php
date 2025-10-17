<?php
// admin/index.php — Nástěnka (index) pro ticketing admin
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
ensure_admin();

/* ====== ČAS & POMOCNÉ ====== */
$tz         = new DateTimeZone('Europe/Prague');
$nowDt      = new DateTime('now', $tz);
$now        = $nowDt->format('Y-m-d H:i:s');
$todayStart = (new DateTime('today', $tz))->format('Y-m-d 00:00:00');

function db_scalar(string $sql, array $p = []) {
  try { $q = db()->prepare($sql); $q->execute($p); return (float)$q->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}
function db_rows(string $sql, array $p = []) {
  try { $q = db()->prepare($sql); $q->execute($p); return $q->fetchAll(PDO::FETCH_ASSOC); }
  catch (Throwable $e) { return []; }
}
function fmt_money(float $v, string $ccy='CZK'): string {
  try { return (new NumberFormatter('cs-CZ', NumberFormatter::CURRENCY))->formatCurrency(round($v), strtoupper($ccy)); }
  catch (Throwable $e) { $sym = strtoupper($ccy)==='EUR'?'€':'Kč'; return number_format(round($v),0,',',' ').' '.$sym; }
}
function dt_cz(?string $s): string {
  if (!$s) return '—';
  try { return (new DateTime($s))->format('j. n. Y, H:i'); } catch(Throwable $e){ return (string)$s; }
}

/* ====== METODIKA PENĚZ (sjednocená) ====== */
$SUM_MONEY = "COALESCE(SUM(CASE WHEN total_cents >= 10000 THEN ROUND(total_cents/100.0) ELSE total_cents END),0)";

/* ====== KPI ====== */
$revenueToday = db_scalar("SELECT $SUM_MONEY FROM orders WHERE status='paid' AND COALESCE(paid_at, created_at) BETWEEN ? AND ?", [$todayStart,$now]);
$revenue7d    = db_scalar("SELECT $SUM_MONEY FROM orders WHERE status='paid' AND COALESCE(paid_at, created_at) BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?", [$now,$now]);
$revenue30d   = db_scalar("SELECT $SUM_MONEY FROM orders WHERE status='paid' AND COALESCE(paid_at, created_at) BETWEEN DATE_SUB(?, INTERVAL 29 DAY) AND ?", [$now,$now]);

$ordersToday  = (int)db_scalar("SELECT COUNT(*) FROM orders WHERE status='paid' AND COALESCE(paid_at, created_at) BETWEEN ? AND ?", [$todayStart,$now]);

/* ====== GRAF — přepínač 7/30/90 ====== */
$range = strtolower((string)($_GET['range'] ?? '30d'));
$rangeDays = in_array($range, ['7d','30d','90d'], true) ? (int)rtrim($range,'d') : 30;
$rangeStart = (new DateTime('-'.($rangeDays-1).' days', $tz))->format('Y-m-d 00:00:00');

$daily = db_rows("
  SELECT d, SUM(s) s FROM (
    SELECT DATE(COALESCE(paid_at, created_at)) d,
           CASE WHEN total_cents >= 10000 THEN ROUND(total_cents/100.0) ELSE total_cents END s
    FROM orders
    WHERE status='paid'
      AND COALESCE(paid_at, created_at) BETWEEN ? AND ?
  ) x
  GROUP BY d
  ORDER BY d ASC
", [$rangeStart, $now]);

$chartLabels = [];
$chartVals   = [];
$cursor = new DateTime($rangeStart, $tz);
$end    = new DateTime('today', $tz);
$map = [];
foreach ($daily as $r) { $map[$r['d']] = (float)$r['s']; }
while ($cursor <= $end) {
  $key = $cursor->format('Y-m-d');
  $chartLabels[] = $cursor->format('j.n.');
  $chartVals[]   = (float)($map[$key] ?? 0);
  $cursor->modify('+1 day');
}

/* ====== NEJBLIŽŠÍ AKCE (stejná logika jako tvůj první funkční kód) ======
   Ukazujeme jen 'on_sale' a 'sold_out' — ať se to zaručeně zobrazuje jako dřív. */
$events = db_rows("
  SELECT id, title, slug, venue_name, starts_at, status
  FROM events
  WHERE status IN ('on_sale','sold_out')
  ORDER BY starts_at ASC
  LIMIT 20
");

/* Kapacita a prodané — fallbacky pro obě režie */
function event_capacity_fallback(string $eventId): int {
  // 1) Seatmap poslední verze
  try {
    $st = db()->prepare("SELECT schema_json FROM event_seatmaps WHERE (event_id=? OR event_id=UNHEX(REPLACE(?, '-', ''))) ORDER BY version DESC LIMIT 1");
    $st->execute([$eventId,$eventId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['schema_json'])) {
      $d = json_decode($row['schema_json'], true);
      if (isset($d['seats']) && is_array($d['seats'])) return count($d['seats']);
      $cap=0;
      if (!empty($d['rows_meta'])) foreach ($d['rows_meta'] as $m) $cap += (int)($m['seats'] ?? 0);
      if (!empty($d['tables']))    foreach (($d['tables'] ?? []) as $t) $cap += (int)($t['seat_count'] ?? 0);
      if ($cap>0) return $cap;
    }
  } catch(Throwable $e){}
  // 2) GA = sum(ticket_types.capacity)
  try {
    $q = db()->prepare("SELECT COALESCE(SUM(capacity),0) FROM event_ticket_types WHERE (event_id=? OR event_id=UNHEX(REPLACE(?, '-', '')))");
    $q->execute([$eventId,$eventId]);
    return (int)$q->fetchColumn();
  } catch(Throwable $e){ return 0; }
}
function event_sold_fallback(string $eventId): int {
  try {
    $q = db()->prepare("
      SELECT COALESCE(SUM(oi.quantity),0)
      FROM order_items oi
      JOIN orders o ON o.id = oi.order_id
      WHERE (oi.event_id=? OR oi.event_id=UNHEX(REPLACE(?, '-', '')))
        AND o.status IN ('paid','manual_paid')
    ");
    $q->execute([$eventId,$eventId]);
    return (int)$q->fetchColumn();
  } catch(Throwable $e) { return 0; }
}

/* Poslední objednávky */
$lastOrders = db_rows("
  SELECT id, customer_name, total_cents, currency, status, paid_at
  FROM orders
  ORDER BY created_at DESC
  LIMIT 6
");

/* ====== HEADER PROMĚNNÉ ====== */
$admin_event_title = 'Nástěnka';
$admin_show_back   = false;
$admin_back_href   = '';

include __DIR__.'/_header.php';
?>
<style>
.dashboard{ --accent:#2563eb; --panel:#fff; --text:#0b1220; --muted:#5b677a; --border:#e6e9ef; --shadow:0 8px 24px rgba(3,14,38,.06) }
.dashboard *{ box-sizing:border-box }
.dashboard .wrap{ max-width:1180px; margin:0 auto; padding:18px 16px; color:var(--text) }
.dashboard h1{ margin:0 0 8px; font-weight:700; letter-spacing:.2px }
.dashboard .sub{ color:#64748b; margin-bottom:14px }
.grid{ display:grid; gap:14px }
.kpis{ grid-template-columns:repeat(4,1fr) }
@media (max-width:1100px){ .kpis{ grid-template-columns:repeat(2,1fr) } }
.card{ background:var(--panel); border:1px solid var(--border); border-radius:14px; padding:14px; box-shadow:var(--shadow) }
.title{ font-size:12px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:.6px; margin-bottom:6px }
.kpi{ display:flex; align-items:flex-end; justify-content:space-between }
.kpi .value{ font-size:24px; font-weight:800 }
.search{ position:relative }
.search input{ width:260px; padding:10px 12px 10px 36px; border:1px solid var(--border); border-radius:12px }
.search i{ position:absolute; left:10px; top:50%; transform:translateY(-50%); color:#9aa3af }
.btn{ display:inline-flex; align-items:center; gap:8px; padding:9px 14px; border-radius:12px; border:1px solid #2563eb; background:#2563eb; color:#fff; font-weight:700; text-decoration:none }
.btn:hover{ background:#1d4ed8; border-color:#1d4ed8 }
.table{ width:100%; border-collapse:separate; border-spacing:0 8px }
.table th{ text-align:left; font-size:12px; color:#64748b; padding:0 10px 6px; text-transform:uppercase; letter-spacing:.5px }
.table td{ background:#ffffff; border:1px solid var(--border); padding:10px 12px }
.table tr td:first-child{ border-top-left-radius:10px; border-bottom-left-radius:10px }
.table tr td:last-child{ border-top-right-radius:10px; border-bottom-right-radius:10px }
.right{ text-align:right }
.mono{ font-variant-numeric:tabular-nums }
.chip{ display:inline-flex; align-items:center; gap:6px; padding:6px 10px; border-radius:999px; font-size:12px; border:1px solid #e6e9ef; background:#f8fafc; color:#334155 }
.chip .dot{ width:8px; height:8px; border-radius:999px; background:#64748b }
.chip.on_sale .dot{ background:#10b981 }
.chip.sold_out .dot{ background:#8b5cf6 }
.chart-wrap{ height:260px }
.gbar{position:relative;height:8px;background:#eef2ff;border:1px solid #e5e7eb;border-radius:999px;overflow:hidden}
.gbar>.fill{position:absolute;left:0;top:0;bottom:0;width:0;transition:width .6s cubic-bezier(.2,.8,.2,1);background:linear-gradient(90deg,#2563eb,#1d4ed8)}
.btn-new-action {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #2563eb;
  color: #fff;
  padding: 8px 14px;
  border-radius: 8px;
  font-weight: 600;
  font-size: 14px;
  text-decoration: none;
  border: 1px solid #2563eb;
  transition: background 0.25s ease, box-shadow 0.25s ease;
}
.btn-new-action:hover {
  background: #1d4ed8;
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.15);
}
.btn-new-action i {
  font-size: 14px;
}
</style>

<div class="dashboard">
  <div class="wrap">
<h1 style="display:flex;align-items:center;gap:12px;justify-content:space-between;margin:12px 0 10px">
  <span>Nástěnka</span>
  <a class="btn-new-action" href="/admin/event_edit.php">
    <i class="fa-solid fa-plus"></i> Nová akce
  </a>
</h1>

    <!-- KPI -->
    <section class="grid kpis">
      <div class="card"><div class="title">Tržby dnes</div><div class="kpi"><div class="value mono"><?= fmt_money($revenueToday) ?></div></div></div>
      <div class="card"><div class="title">Posledních 7 dní</div><div class="kpi"><div class="value mono"><?= fmt_money($revenue7d) ?></div></div></div>
      <div class="card"><div class="title">Posledních 30 dní</div><div class="kpi"><div class="value mono"><?= fmt_money($revenue30d) ?></div></div></div>
      <div class="card"><div class="title">Objednávky dnes</div><div class="kpi"><div class="value mono"><?= number_format($ordersToday,0,',',' ') ?></div></div></div>
    </section>

    <div style="height:10px"></div>

    <!-- Graf tržeb s přepínačem období -->
    <section class="grid" style="grid-template-columns:1fr">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div class="title">Denní tržby (<?= (int)$rangeDays ?> dní)</div>
          <div style="display:flex;gap:6px;align-items:center">
            <?php foreach (['7d'=>7,'30d'=>30,'90d'=>90] as $rk=>$rv): $active = ($rk===$range); ?>
              <a class="btn" style="padding:6px 10px;border-radius:8px;border-width:1px;<?= $active?'' : 'background:#fff;color:#2563eb' ?>" href="?range=<?= $rk ?>"><?= $rv ?> dní</a>
            <?php endforeach; ?>
            <a class="btn" style="padding:6px 10px;border-radius:8px;background:#fff;color:#2563eb" href="/admin/orders.php?range=<?= htmlspecialchars($range) ?>"><i class="fa fa-arrow-up-right-from-square"></i> Detail</a>
          </div>
        </div>
        <div class="chart-wrap"><canvas id="revChart"></canvas></div>
      </div>
    </section>

    <div style="height:10px"></div>

    <!-- Nejbližší akce (full width) -->
    <section class="grid" style="grid-template-columns:1fr">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <div class="title">Nejbližší akce</div>
          <a class="btn" style="padding:6px 10px;border-radius:8px;background:#fff;color:#2563eb" href="/admin/events.php">Vše</a>
        </div>

        <div style="overflow:auto">
          <table class="table" style="min-width:880px">
            <thead>
            <tr>
              <th>Akce</th>
              <th>Start</th>
              <th>Stav</th>
              <th>Prodané / Kapacita</th>
              <th class="right">%</th>
              <th class="right">Tržby (30 dní)</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$events): ?>
              <tr><td colspan="6" style="padding:12px;color:#64748b">Žádné dostupné akce.</td></tr>
            <?php endif; ?>

            <?php foreach ($events as $ev):
              $eid  = (string)$ev['id'];
              $cap  = event_capacity_fallback($eid);
              $sold = event_sold_fallback($eid);
              $pct  = $cap>0 ? round($sold*100/$cap) : 0;

              // tržby za posledních 30 dní pro akci
              try {
                $rev = db_scalar("SELECT $SUM_MONEY FROM orders WHERE (event_id=? OR event_id=UNHEX(REPLACE(?, '-', ''))) AND status='paid' AND COALESCE(paid_at, created_at) BETWEEN DATE_SUB(?, INTERVAL 29 DAY) AND ?",
                  [$eid,$eid,$now,$now]
                );
              } catch(Throwable $e){ $rev = 0.0; }
            ?>
              <tr>
                <td>
                  <a href="/admin/event_detail.php?id=<?= htmlspecialchars($eid) ?>"><strong><?= htmlspecialchars((string)$ev['title']) ?></strong></a>
                  <?php if (!empty($ev['venue_name'])): ?>
                    <div style="color:#6b7280;font-size:12px"><?= htmlspecialchars((string)$ev['venue_name']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= dt_cz($ev['starts_at'] ?? null) ?></td>
                <td><span class="chip <?= htmlspecialchars((string)$ev['status']) ?>"><span class="dot"></span> <?= htmlspecialchars((string)$ev['status']) ?></span></td>
                <td class="mono">
                  <?= number_format($sold,0,',',' ') ?> / <?= number_format($cap,0,',',' ') ?>
                  <div class="gbar" style="margin-top:6px"><div class="fill" style="width: <?= $pct ?>%"></div></div>
                </td>
                <td class="right mono"><?= $pct ?>%</td>
                <td class="right mono"><?= fmt_money((float)$rev) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <div style="height:10px"></div>

    <!-- Poslední objednávky -->
    <section class="grid" style="grid-template-columns:1fr 1fr">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
          <div class="title">Poslední objednávky</div>
          <a class="btn" style="padding:6px 10px;border-radius:8px;background:#fff;color:#2563eb" href="/admin/orders.php"><i class="fa fa-receipt"></i> Všechny</a>
        </div>
        <table class="table">
          <thead><tr><th>#</th><th>Zákazník</th><th>Stav</th><th>Zaplaceno</th><th class="right">Částka</th></tr></thead>
          <tbody>
          <?php if (!$lastOrders): ?>
            <tr><td colspan="5" style="padding:12px;color:#64748b">Zatím nic nového.</td></tr>
          <?php endif; ?>
          <?php foreach ($lastOrders as $o):
            $raw = (int)($o['total_cents'] ?? 0);
            $amt = ($raw >= 10000) ? round($raw/100.0) : $raw;
            $ccy = strtoupper((string)($o['currency'] ?? 'CZK'));
          ?>
            <tr>
              <td class="mono"><a href="/admin/order.php?id=<?= (int)$o['id'] ?>" style="color:#2563eb;text-decoration:none">#<?= (int)$o['id'] ?></a></td>
              <td><strong><?= htmlspecialchars((string)($o['customer_name'] ?: '—')) ?></strong></td>
              <td><span class="chip <?= htmlspecialchars((string)$o['status']) ?>"><span class="dot"></span> <?= htmlspecialchars((string)$o['status']) ?></span></td>
              <td class="mono"><?= dt_cz($o['paid_at'] ?? null) ?></td>
              <td class="right mono"><?= fmt_money((float)$amt, $ccy) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="title">Rezervováno pro další widget</div>
        <div style="color:#64748b">(Momentálně záměrně prázdné.)</div>
      </div>
    </section>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
const values = <?= json_encode(array_map('floatval',$chartVals)) ?>;
const ctx = document.getElementById('revChart');
if (ctx) {
  new Chart(ctx, {
    type:'bar',
    data:{ labels: labels, datasets:[{ label:'Tržby', data: values }] },
    options:{
      responsive:true, maintainAspectRatio:false,
      scales:{ y:{beginAtZero:true}, x:{grid:{display:false}} },
      plugins:{ legend:{display:false} }
    }
  });
}
</script>

<?php include __DIR__.'/_footer.php';
