<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__.'/inc/db.php';

$rows = db()->query("
  SELECT id, title, slug, venue_name, address, starts_at, cover_image_url, status
  FROM events
  WHERE status IN ('on_sale','sold_out')
  ORDER BY starts_at ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8">
  <title>Šlágr Koncerty – Přehled akcí</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ========= SCOPE: jen pro tuto stránku ========= */
    .events-page{
      --bg:#e9ecf4;
      --panel:#ffffff;
      --text:#0b1220;
      --muted:#5b677a;
      --border:#e6e9ef;
      --radius:16px;
      --shadow:0 10px 28px rgba(3,14,38,.10);
      --accent:#2563eb;
      --accent-600:#1d4ed8;
    }
    .events-page, .events-page *{ box-sizing:border-box }
    .events-page a{ color:inherit; text-decoration:none }
    body.events-body{ margin:0; background:var(--bg); color:var(--text); font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif }

    .events-page .wrap{ max-width:1180px; margin:28px auto 40px; padding:0 18px }
    .events-page h1{
      margin:42px 0 36px; text-align:center;
      font-family:"Manrope",system-ui; font-weight:800; letter-spacing:.2px; font-size:28px;
    }

    /* ======= GRID 3/2/1 ======= */
    .events-page .cards-grid{
      display:grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap:20px;
      align-items: stretch;
    }
    @media (max-width: 1024px){
      .events-page .cards-grid{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 640px){
      .events-page .cards-grid{ grid-template-columns: 1fr; }
    }

    /* ======= KARTA ======= */
    .events-page .e-card{
      display:flex; flex-direction:column; overflow:hidden;
      border:1px solid var(--border); border-radius:var(--radius);
      background:var(--panel); box-shadow:var(--shadow);
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
      min-width: 0; /* zabrání rozbití gridu dlouhými názvy */
    }
    .events-page .e-card:hover{
      transform: translateY(-2px);
      box-shadow: 0 16px 46px rgba(3,14,38,.14);
      border-color:#dfe5ef;
    }

    /* Obrázek */
    .events-page .thumb-link{ display:block }
    .events-page .thumb-wrap{ position:relative; aspect-ratio:16/9; background:#eef2f6; overflow:hidden }
    .events-page .thumb{ width:100%; height:100%; object-fit:cover; display:block }
    .events-page .status-badge{
      position:absolute; left:12px; top:12px; z-index:2;
      font-size:12px; font-weight:800;
      padding:6px 10px; border-radius:999px;
      background:rgba(255,255,255,.92); color:#0b1220; border:1px solid #e5e7eb;
    }

    /* Tělo karty */
    .events-page .card-body{
      display:grid;
      grid-template-columns: 1fr 3fr;      /* datum | pravá část */
      grid-template-rows: auto auto;       /* headline full-width + druhý řádek */
      gap:0;
      border-top:1px solid #f0f2f6;
      background:linear-gradient(to top, rgba(255,255,255,.36), rgba(255,255,255,.18));
    }
    .events-page .headline{
      grid-column: 1 / -1;
      padding: 12px 14px 10px;
      border-bottom:1px solid #f2f4f8;
      min-width:0;
    }
    .events-page .title{
      font-family:"Manrope",system-ui; font-weight:800; font-size:19px; line-height:1.25;
      margin:0 0 4px 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
    }
    .events-page .venue{
      color:var(--muted); font-size:12px; line-height:1.35;
      display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
    }

    /* Datum vlevo */
    .events-page .date-col{
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      gap:4px; padding:14px 10px; text-align:center; border-right:1px solid #eef1f6;
    }
    .events-page .dm{ font-family:"Manrope"; font-weight:800; font-size:20px; line-height:1 }
    .events-page .y{ font-weight:700; font-size:12px; opacity:.7 }

    /* Pravý sloupec: tlačítko + progress */
    .events-page .right-col{ display:grid; grid-template-rows:auto 1fr; gap:10px; padding:14px; min-width:0 }
    .events-page .btn{
      display:inline-flex; align-items:center; justify-content:center; gap:8px;
      padding:10px 12px; border-radius:12px; font-weight:700; font-size:14px;
      border:1px solid var(--accent); color:#fff; background:var(--accent);
      transition: background .15s ease, border-color .15s ease, transform .05s ease, box-shadow .15s ease;
      cursor:pointer;
      box-shadow:0 10px 24px rgba(37, 100, 235, 0.08);
      width:100%;
    }
    .events-page .btn:hover{ background:var(--accent-600); border-color:var(--accent-600) }
    .events-page .btn:active{ transform: translateY(1px) }

    /* Mini progress */
    .events-page .mini-progress{
      border:1px solid #e6e9ef; border-radius:12px; padding:10px; background:#fff;
    }
    .events-page .mini-progress .head{
      display:flex; justify-content:space-between; align-items:center;
      margin-bottom:8px; font-size:13px; color:#475569
    }
    .events-page .mini-progress .nums{ font-variant-numeric: tabular-nums }
    .events-page .mini-progress .bar{
      position:relative; height:8px; background:#eef2ff; border:1px solid #e5e7eb; border-radius:999px; overflow:hidden
    }
    .events-page .mini-progress .bar > span{
      position:absolute; left:0; top:0; bottom:0; width:0;
      transition:width .5s cubic-bezier(.2,.8,.2,1);
      background:linear-gradient(90deg,#2563eb,#1d4ed8)
    }
    .date-col .t{
margin-top: 5px;
    font-weight: 500;
    font-size: 15px;
    color: #919191;    /* mírný akcent, klidně uprav */
  letter-spacing:.2px;
}


    /* Mobil – jeden sloupec v kartě (datum nahoře) */
    @media (max-width:560px){
      .events-page .card-body{ grid-template-columns:1fr; }
      .events-page .date-col{ border-right:0; border-bottom:1px solid #eef1f6; }
    }

    /* Empty state */
    .events-page .empty{
      background:#fff; border:1px dashed #dfe5ef; border-radius:16px; padding:24px; color:#64748b; text-align:center
    }
  </style>
</head>
<body class="events-body">
<div class="events-page">
  <div class="wrap">
    <h1>Aktuální koncerty</h1>

    <?php if (empty($rows)): ?>
      <div class="empty">Momentálně nejsou žádné dostupné akce.</div>
    <?php else: ?>
      <div class="cards-grid" id="eventsList">
        <?php foreach ($rows as $e):
          $DM = $Y = '';
         try {
  $dt = new DateTime($e['starts_at']);
  $DM   = $dt->format('j.n.');
  $Y    = $dt->format('Y');
  $TIME = $dt->format('H:i');
} catch(Throwable $t) {}
          $isSold = strtolower((string)$e['status']) === 'sold_out';
          $venueLine = trim(($e['venue_name'] ?? '') . (!empty($e['address']) ? ' ' . $e['address'] : ''));
        ?>
        <article class="e-card">
          <a class="thumb-link" href="/e/<?= urlencode($e['slug']) ?>">
            <div class="thumb-wrap">
              <?php if(!empty($e['cover_image_url'])): ?>
                <img class="thumb" src="<?= htmlspecialchars($e['cover_image_url']) ?>" alt="<?= htmlspecialchars($e['title']) ?>" loading="lazy" decoding="async">
              <?php else: ?>
                <img class="thumb" src="data:image/svg+xml,<?= rawurlencode('<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 800 450\'><rect fill=\'#eef2f6\' width=\'800\' height=\'450\'/></svg>') ?>" alt="" loading="lazy" decoding="async">
              <?php endif; ?>
              <span class="status-badge"><?= htmlspecialchars($isSold ? 'Vyprodáno' : 'V prodeji') ?></span>
            </div>
          </a>

          <div class="card-body">
            <div class="headline">
              <h3 class="title"><?= htmlspecialchars($e['title']) ?></h3>
              <?php if($venueLine !== ''): ?>
                <div class="venue"><?= htmlspecialchars($venueLine) ?></div>
              <?php endif; ?>
            </div>

            <div class="date-col">
              <?php if($DM): ?><div class="dm"><?= htmlspecialchars($DM) ?></div><?php endif; ?>
              <?php if($Y):  ?><div class="y"><?= htmlspecialchars($Y)  ?></div><?php endif; ?>
                  <?php if($TIME): ?><div class="t"><?= htmlspecialchars($TIME) ?></div><?php endif; ?>

            </div>

            <div class="right-col">
              <a class="btn" href="/e/<?= urlencode($e['slug']) ?>">
                <?= $isSold ? 'Detail akce' : 'Vstupenky' ?>
              </a>

              <div class="mini-progress"
                   data-event-id="<?= htmlspecialchars($e['id']) ?>"
                   data-stats-url="/event_stats_public.php">
                <div class="head">
                  <div class="label">Prodaných vstupenek</div>
                  <div class="nums">
                    <span class="sold">0</span>
                    (<span class="pct">0</span>%)
                  </div>
                </div>
                <div class="bar"><span style="width:0%"></span></div>
              </div>
            </div>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
         . '://' . $_SERVER['HTTP_HOST'];

$schemaEvents = [];
foreach ($rows as $i => $ev) {
  // ISO 8601 začátek
  $startIso = '';
  try { $startIso = (new DateTime($ev['starts_at']))->format(DateTime::ATOM); } catch(Throwable $t) {}

  // URL a obrázek
  $eUrl   = $baseUrl . '/e/' . rawurlencode($ev['slug']);
  $eImage = !empty($ev['cover_image_url']) ? $ev['cover_image_url'] : null;

  // Místo/Adresa
  $placeName = trim((string)($ev['venue_name'] ?? ''));
  $address   = trim((string)($ev['address'] ?? ''));

  // Stav a dostupnost
  $statusRaw = strtolower((string)($ev['status'] ?? ''));
  $eventStatus = 'https://schema.org/EventScheduled';
  $availability = 'https://schema.org/InStock';
  if ($statusRaw === 'sold_out') {
    $availability = 'https://schema.org/SoldOut';
  }

  $item = [
    '@type'      => 'Event',
    'name'       => (string)$ev['title'],
    'startDate'  => $startIso,
    'eventStatus'=> $eventStatus,
    'url'        => $eUrl,
  ];

  if ($eImage) $item['image'] = [$eImage];

  if ($placeName || $address) {
    $item['location'] = [
      '@type' => 'Place',
      'name'  => $placeName ?: 'Místo konání',
      'address' => $address ?: null,
    ];
  }

  // Nabídka (bez ceny – aspoň url a dostupnost)
  $item['offers'] = [
    '@type'        => 'Offer',
    'url'          => $eUrl,
    'availability' => $availability
  ];

  $schemaEvents[] = $item;
}

if (!empty($schemaEvents)):
?>
<script type="application/ld+json">
<?= json_encode([
  '@context' => 'https://schema.org',
  '@type'    => 'ItemList',
  'itemListElement' => array_map(function($ev, $idx){
    return [
      '@type'    => 'ListItem',
      'position' => $idx + 1,
      'item'     => $ev
    ];
  }, $schemaEvents, array_keys($schemaEvents))
], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>

<script>
(function(){
  const blocks = document.querySelectorAll('.mini-progress[data-event-id]');
  if (!blocks.length) return;

  function render(block, data){
    const sold = +data.sold || 0;
    const cap  = +data.capacity || 0;
    const pct  = cap > 0 ? Math.round(sold*100/cap) : 0;

    block.querySelector('.sold').textContent = sold.toLocaleString('cs-CZ');
    block.querySelector('.pct').textContent  = pct;
    const bar = block.querySelector('.bar > span');
    if (bar) bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
  }

  async function load(block){
    const id = block.getAttribute('data-event-id');
    const base = block.getAttribute('data-stats-url') || '/event_stats_public.php';
    try{
      const res = await fetch(`${base}?id=${encodeURIComponent(id)}`, {cache:'no-store'});
      if(!res.ok) return;
      const json = await res.json();
      if (json && !json.error) render(block, json);
    }catch(_){}
  }

  blocks.forEach(load);
  setInterval(()=> blocks.forEach(load), 20000);
})();
</script>
</body>
</html>
