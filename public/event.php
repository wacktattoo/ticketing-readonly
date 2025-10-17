<?php
// event.php – veřejný detail akce s legendou kategorií, cenami z JSON tiers a popisky řad
require_once __DIR__ . '/../inc/db.php';

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Přečti slug nebo id – funguje pro /e/{slug|id} i ?slug= / ?id=
$val = $_GET['slug'] ?? $_GET['id'] ?? '';

// fallback z URL cesty /e/xxx
if ($val === '') {
  $path  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $parts = array_values(array_filter(explode('/', $path)));
  $val   = $parts[count($parts)-1] ?? '';
}

if ($val === '') { http_response_code(404); echo 'Akce nenalezena'; exit; }

// DETAIL: bez filtru na status (archiv se má zobrazit po přímém vstupu)
$st = db()->prepare("SELECT * FROM events WHERE slug = :v OR id = :v LIMIT 1");
$st->execute([':v' => $val]);
$event = $st->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); echo 'Akce nenalezena'; exit; }
// pro zbytek skriptu používej $slug (když není, spadne na id)
$slug = $event['slug'] ?: $event['id'];


// Seatmap + runtime
$sm = db()->prepare("SELECT schema_json FROM event_seatmaps WHERE event_id=? ORDER BY version DESC LIMIT 1");
$sm->execute([$event['id']]);
$seatmap = $sm->fetch(PDO::FETCH_ASSOC);
$schema = $seatmap ? json_decode($seatmap['schema_json'], true)
                   : ['width'=>800,'height'=>480,'tiers'=>[],'rows'=>[],'seats'=>[],'tables'=>[]];

$rt = db()->prepare("SELECT seat_id, state FROM seats_runtime WHERE event_id=?");
$rt->execute([$event['id']]);
$states = []; foreach($rt as $row){ $states[$row['seat_id']] = $row['state']; }
// GA (general admission) ticket types bez místa
$tt = db()->prepare("SELECT id, code, name, prices_json, color, capacity, sold FROM event_ticket_types WHERE event_id=? ORDER BY id ASC");
$tt->execute([$event['id']]);
$ticketTypes = $tt->fetchAll(PDO::FETCH_ASSOC);

// Připrav pro JS: prices_json -> asociativní pole + zbývající kapacita
$gaTypesJs = [];
foreach ($ticketTypes as $t) {
  $prices = [];
  try { $prices = json_decode($t['prices_json'], true) ?? []; } catch(Throwable $e) {}
  $remain = max(0, (int)$t['capacity'] - (int)$t['sold']);
  $gaTypesJs[] = [
    'id'   => (int)$t['id'],
    'code' => (string)$t['code'],
    'name' => (string)$t['name'],
    'prices' => [
      'CZK' => (int)($prices['CZK'] ?? 0),
      'EUR' => (int)($prices['EUR'] ?? 0),
    ],
    'color' => $t['color'] ?: '#2563eb',
    'remaining' => $remain,
  ];
}
// --- Režim prodeje & co zobrazit ---
$mode = $event['selling_mode'] ?? 'mixed';
$renderSeats = ($mode !== 'ga')    && !empty($schema['seats']);
$renderGA    = ($mode !== 'seats') && !empty($gaTypesJs);


// Čas od–do
$startsAt = $endsAt = null; $startsHuman = $endsHuman = '';
try {
  if (!empty($event['starts_at'])) { $startsAt = new DateTime($event['starts_at'], new DateTimeZone('Europe/Prague')); $startsHuman = $startsAt->format('d.m.Y H:i'); }
  if (!empty($event['ends_at'])) { $endsAt = new DateTime($event['ends_at'], new DateTimeZone('Europe/Prague')); }
  elseif (!empty($event['duration_min']) && $startsAt) { $endsAt = (clone $startsAt)->modify('+'.(int)$event['duration_min'].' minutes'); }
  if ($endsAt) { $endsHuman = $endsAt->format('d.m.Y H:i'); }
} catch (Throwable $e) { $startsHuman = e($event['starts_at'] ?? ''); }

function cz_month_short(int $n): string { $m=[1=>'led',2=>'úno',3=>'bře',4=>'dub',5=>'kvě',6=>'čvn',7=>'čvc',8=>'srp',9=>'zář',10=>'říj',11=>'lis',12=>'pro']; return $m[$n]??''; }
$dateLine=''; $timeSpan='';
if ($startsAt instanceof DateTime) { $dateLine = (int)$startsAt->format('j').'. '.cz_month_short((int)$startsAt->format('n')).' '.(int)$startsAt->format('Y'); $timeSpan=$startsAt->format('H:i'); if($endsAt)$timeSpan.=' – '.$endsAt->format('H:i'); }
$stLower = strtolower((string)($event['status'] ?? '')); $statusLabel = ($stLower==='sold_out')?'Vyprodáno':'V prodeji'; $statusClass = ($stLower==='sold_out')?'sold':'onsale';

// Obrázky: cover + galerie z event_images
$cover=''; if(!empty($event['cover_image_url'])) $cover=(string)$event['cover_image_url'];
$gallery=[]; try{ $qi=db()->prepare("SELECT url, is_cover FROM event_images WHERE event_id=? ORDER BY sort_order ASC, id ASC"); $qi->execute([$event['id']]); $rows=$qi->fetchAll(PDO::FETCH_ASSOC)?:[]; foreach($rows as $r){ if(!empty($r['url'])){ $u=trim($r['url']); if($u!=='') $gallery[]=$u; if(!$cover && !empty($r['is_cover']) && (int)$r['is_cover']===1) $cover=$u; } } }catch(Throwable $e){}
if(!$cover && $gallery) $cover=$gallery[0];
$gallery = array_values(array_filter(array_map(function($src){ $s=trim((string)$src); return $s!==''?$s:null; }, $gallery)));

?><!doctype html>
<html lang="cs">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($event['title']) ?></title>
  <?php if (!empty($event['cover_image_url'])): ?>
  <meta property="og:image" content="<?= htmlspecialchars($event['cover_image_url']) ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($event['cover_image_url']) ?>">
<?php endif; ?>
  <style>
    :root{--bg:#f7f8fb;--panel:#ffffff;--text:#0b1220;--muted:#5b677a;--border:#e6e9ef;--accent:#2563eb;--accent-600:#1d4ed8;--accent-50:#eef2ff;--radius:16px;--shadow:0 8px 30px rgba(3,14,38,.06)}
    *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;line-height:1.5}
    a{color:var(--accent);text-decoration:none} a:hover{text-decoration:underline} img{max-width:100%;display:block}
    .wrap{max-width:1180px;margin:0 auto;padding:28px 20px}
    .hero{display:grid;grid-template-columns:0.8fr 1fr;gap:40px;align-items:start;margin-bottom:18px}
    @media (max-width:960px){.hero{grid-template-columns:1fr}}
.cover{
  background:#f1f4f9;
  border:1px solid var(--border);
  border-radius:var(--radius);
  overflow:hidden;
  box-shadow:var(--shadow);

  /* zrušit pevné proporce boxu */
  /* aspect-ratio: 4/3; */
  /* max-height: 360px; */
}

.cover img{
  display:block;
  width:100%;
  height:auto;           /* nechá výšku podle skutečného obrázku */
}
   .thumbs{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:10px}
    .thumbs img{width:100%;aspect-ratio:1/1;object-fit:cover;border-radius:12px;border:1px solid var(--border);background:#f7f8fb;transition:transform .15s, box-shadow .15s;box-shadow:0 2px 10px rgba(3,14,38,.04);cursor:pointer}
    .thumbs img:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(3,14,38,.08)}
    .eyeh1{font-size:40px;line-height:1.1;margin:24px 0 6px} @media (max-width:560px){.eyeh1{font-size:32px}}
    .status-badge{display:inline-block;font-size:13px;font-weight:600;padding:6px 10px;border-radius:999px;border:1px solid var(--border);background:#eef6ff;color:#0b4aa6}
    .status-badge.sold{background:#fff1f2;color:#9f1239;border-color:#ffe4e6}
    .meta-row{display:flex;align-items:center;gap:10px;color:#36455b;margin-top:10px}.meta-row .label{min-width:64px;color:#5b677a}
    .ico{display:inline-flex;width:18px;height:18px}.ico svg{width:18px;height:18px;display:block}.sep{color:#9aa3b2}
    .grid{display:grid;grid-template-columns:2fr 1fr;gap:24px} @media (max-width:960px){.grid{grid-template-columns:1fr}}
    .card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow)} .card h3{margin:0 0 12px;font-size:22px}
    .map iframe{width:100%;height:320px;border:0;border-radius:12px}
/* centrální zarovnání a prostor okolo */
.seat-area{
  display:flex;
  justify-content:center;
  align-items:flex-start;
  padding:12px 8px;
  position:relative;
}

/* vnitřní plátno seatmapy */
#seat-wrap{
  position:relative;
  transform-origin: top center; /* pro responsivní scale */
  margin: 0 auto;               /* fallback na centrování */
}/* Pódium */
.stage{
  position:absolute;
  left:0; top:0;
  background:rgba(17,24,39,.06);
  color:#111827;
  display:flex; align-items:center; justify-content:center;
  border-radius:8px;
  font-weight:700;
  z-index:1; /* pod sedadly */
}
    .row-label{
  position:absolute;
  transform:translate(-50%,-50%);
  font-size:12px;
  font-weight:700;
  color:#374151;
  pointer-events:none;
  white-space:nowrap;
  background:rgba(255,255,255,.8);
  padding:0 6px;
  border-radius:6px;
  z-index:4;
}
.table-visual{
  position:absolute;
  border:2px solid #cbd5e1;
  border-radius:50%;
  background:#f1f5f9;
  z-index:2;  /* pod sedadly */
}
.table-label{
  position:absolute;
  left:50%; top:50%;
  transform:translate(-50%,-50%);
  font-size:12px;
  font-weight:700;
  color:#334155;
  pointer-events:none;
  z-index:3;
}
    .seat-dot{position:absolute;width:18px;height:18px;border-radius:50%;transform:translate(-50%,-50%);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.2); z-index:3;}
    .seat-dot.sold{background:#cbd5e1 !important;cursor:not-allowed;box-shadow:none}
/* Vybrané sedadlo – světle oranžová */
.seat-dot.selected {
  background: #fdba74 !important;   /* světle oranžová */
  cursor: pointer;
  box-shadow: none;
}

.shape{
  position:absolute;
  z-index:0;            /* úplně pod vším */
  pointer-events:none;
  border:1px solid transparent; /* pro stroke u rect/round */
}

.shape-svg{
  position:absolute;
  left:0; top:0;
  width:100%; height:100%;
  z-index:0;            /* pod pódiem, stoly i sedadly */
  pointer-events:none;
}

/* sedadlo držené jiným zákazníkem (runtime state = 'held') */
.seat-dot.held {
  background: #e5e7eb !important;    /* světlejší šedá */
  cursor: not-allowed;
  box-shadow: none;
}
    .seat-label{pointer-events:none;line-height:1}
/* Kontejner legendy – zarovnat stavové čipy vpravo */
.legend{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:10px;
  align-items:center;
}
/* tlačítka = ukazatel */
.btn, .btn.small { cursor: pointer; }

/* info blok dole přes celou šířku */
.info-wide { margin-top: 24px; }
/* sjednocená šířka single-column layoutu */
.main-col {
  display: block;
}

/* “karta” s informacemi přes celou šířku článku */
.info-card {
  margin-top: 18px;
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 18px;
  box-shadow: var(--shadow);
}

.info-card h3 {
  margin: 0 0 12px;
  font-size: 20px;
}

/* dvousloupcová mřížka v info boxu (na mobilu se složí) */
.info-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(160px, 1fr));
  gap: 10px 14px;
  font-size: 14px;
  color: #36455b;
}
@media (max-width: 720px){
  .info-grid { grid-template-columns: 1fr; }
}

/* tlačítko nahoře v hero by mělo naznačit kliknutelnost */
#btnOpenTicketsTop { cursor: pointer; }

/* stejný základ pro oba typy čipů */
.legend .chip{
  display:inline-flex;
  align-items:center;
  gap:8px;
  border:1px solid var(--border);
  background:#fff;
  border-radius:999px;
  padding:6px 10px;
  box-shadow:var(--shadow);
  font-size:13px;
}
.legend .dot{ width:12px;height:12px;border-radius:50% }

/* — TIER čipy (ceny) — ponecháme stejně, jen je trošku ztlumíme vizuálně */
.legend .chip.tier{ opacity:.92 }

/* — STAVOVÉ čipy (vybrané/držené/vyprodáno) — odlišný vzhled + barvy */
.legend .chip.state{
  background:#f8fafc;
  
  border-color:#dbeafe;
}
.back-btn-wrap{
  max-width:1180px;
  margin:20px auto 0;
  padding:0 18px;
}
.back-btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  font-family:"Manrope", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
  font-weight:600;
  font-size:15px;
  text-decoration:none;
  color:#2563eb;
  border:1px solid var(--accent,#2563eb);
  padding:10px 16px;
  border-radius:12px;
  transition:background .2s ease, border-color .2s ease, transform .05s ease;
}
.back-btn:hover{
  background:var(--accent-600,#1d4ed8);
  text-decoration:none;
  color:#fff;
  border-color:var(--accent-600,#1d4ed8);
}
.back-btn:active{
  transform:translateY(1px);
}
.back-btn svg{
  width:18px; height:18px;
  fill:currentColor;
}

.legend .dot.state-selected{ background:#fdba74; } /* světle oranžová */
.legend .dot.state-held{     background:#bfdbfe; } /* světle modrá */
.legend .dot.state-sold{     background:#cbd5e1; } /* šedá */
/* Akordeon pro výběr míst */
#tickets-section { overflow: hidden; transition: grid-template-rows .25s ease, opacity .2s ease; display:grid; grid-template-rows:0fr; opacity:.0; }
#tickets-section.open { grid-template-rows:1fr; opacity:1; }
#tickets-inner { min-height:0; } /* aby fungoval grid 0fr/1fr trik */

.btn.small {
  padding:12px 20px;
  font-size:14px;
  border-radius:10px;
}

/* pružný oddělovač pro odtlačení stavové části doprava */
.legend .spacer{ flex:1 }
    .legend .name{font-weight:600;color:#0b1220}
    .legend .price{color:#5b677a;font-size:13px}
    .cart{margin-top:16px;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px;box-shadow:var(--shadow)}
    .cart-summary{display:flex;justify-content:space-between;align-items:center;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}
    .price{font-weight:700}.muted{color:var(--muted);font-size:13px}
    .btn{display:inline-flex;align-items:center;gap:10px;padding:12px 18px;border-radius:12px;font-weight:600;border:1px solid var(--accent);color:#fff;background:var(--accent);box-shadow:0 6px 18px rgba(37,99,235,.25)} .btn:hover{background:var(--accent-600)} .btn:disabled{opacity:.6;cursor:not-allowed;box-shadow:none}
    footer{color:#6b7280;text-align:center;padding:30px 0}
  </style>
</head>
<body>
  <div class="back-btn-wrap">
  <a href="/index.php" class="back-btn">
    <svg viewBox="0 0 20 20"><path d="M12.293 15.707a1 1 0 0 0 0-1.414L8.414 10l3.879-4.293a1 1 0 1 0-1.414-1.414l-4.586 5a1 1 0 0 0 0 1.414l4.586 5a1 1 0 0 0 1.414 0z"/></svg>
    Zpět na přehled akcí
  </a>
</div>

  <div class="wrap">
    <section class="hero">
      <div class="hero-left">
        <div class="cover"><?php if ($cover): ?><img src="<?= e($cover) ?>" alt="<?= e($event['title']) ?>" loading="lazy" decoding="async"><?php else: ?><div style="display:flex;align-items:center;justify-content:center;height:100%;color:#777">Bez obrázku</div><?php endif; ?></div>
        <?php if (!empty($gallery)): ?><div class="thumbs" aria-label="Galerie"><?php $i=0; foreach ($gallery as $src): ?><img src="<?= e($src) ?>" data-full="<?= e($src) ?>" data-gidx="<?= $i++ ?>" alt="<?= e($event['title']) ?>" loading="lazy" decoding="async"><?php endforeach; ?></div><?php endif; ?>
      </div>
      <div class="hero-right">
        <h1 class="eyeh1"><?= e($event['title']) ?></h1>
        <div class="meta-row"><span class="status-badge <?= e($statusClass) ?>"><?= e($statusLabel) ?></span></div>
        <div class="meta-row"><span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span><span><?= e($dateLine) ?><?php if ($timeSpan): ?><span class="sep"> (</span><?= e($timeSpan) ?><span class="sep">)</span><?php endif; ?></span></div>
<div class="meta-row">
  <span class="ico" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
      <path d="M12 22s7-5.5 7-12a7 7 0 1 0-14 0c0 6.5 7 12 7 12Z"/><circle cx="12" cy="10" r="3"/>
    </svg>
  </span>
  <span>
    <?= e($event['venue_name'] ?? '') ?>
    <?php if (!empty($event['address'])): ?><?= e($event['address']) ?><?php endif; ?>
  </span>
    </div>  
    <div>
  <button id="btnOpenTicketsTop" class="btn" style="margin-top:12px;">Koupit vstupenky</button>
    </div>   
 </div>
    </section>
<section class="main-col">
  <article class="card">
    <h3>O akci</h3>
    <div class="content">
      <?php if(!empty($event['description'])): ?>
        <?= $event['description'] ?>
      <?php else: ?>
        <p>Popis bude doplněn.</p>
      <?php endif; ?>
    </div>

    <!-- NOVÝ BOX: Informace -->
    <div class="info-card">
      <h3>Informace</h3>
      <div class="info-grid">
        <?php if(!empty($event['address'])): ?>
          <div><strong>Adresa:</strong> <?= e($event['address']) ?></div>
        <?php endif; ?>
        <?php if(!empty($event['venue_name'])): ?>
          <div><strong>Místo:</strong> <?= e($event['venue_name']) ?></div>
        <?php endif; ?>
        <?php if(!empty($event['city'])): ?>
          <div><strong>Město:</strong> <?= e($event['city']) ?></div>
        <?php endif; ?>

        <?php
          $event['organizer_name']   = $event['organizer_name']   ?? ($event['organizer'] ?? null);
          $event['official_website'] = $event['official_website'] ?? ($event['organizer_url'] ?? ($event['website'] ?? null));
          $event['organizer_email']  = $event['organizer_email']  ?? ($event['contact_email'] ?? ($event['email'] ?? null));
          $event['organizer_phone']  = $event['organizer_phone']  ?? ($event['contact_phone'] ?? ($event['phone'] ?? null));
          $event['facebook']         = $event['facebook']         ?? ($event['facebook_url'] ?? ($event['fb_page'] ?? null));
        ?>
        <?php if(!empty($event['organizer_name'])): ?>
          <div><strong>Pořadatel:</strong> <?= e($event['organizer_name']) ?></div>
        <?php endif; ?>
        <?php if(!empty($event['official_website'])): ?>
          <div><strong>Oficiální web:</strong> <a href="<?= e($event['official_website']) ?>" target="_blank" rel="noopener"><?= e($event['official_website']) ?></a></div>
        <?php endif; ?>
        <?php if(!empty($event['organizer_email'])): ?>
          <div><strong>E-mail:</strong> <a href="mailto:<?= e($event['organizer_email']) ?>"><?= e($event['organizer_email']) ?></a></div>
        <?php endif; ?>
        <?php if(!empty($event['organizer_phone'])): ?>
          <div><strong>Telefon:</strong> <a href="tel:<?= e($event['organizer_phone']) ?>"><?= e($event['organizer_phone']) ?></a></div>
        <?php endif; ?>
        <?php if(!empty($event['facebook'])): ?>
          <div><strong>Facebook:</strong> <a href="<?= e($event['facebook']) ?>" target="_blank" rel="noopener"><?= e($event['facebook']) ?></a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Mapa -->
    <?php if(!empty($event['map_embed_url'])): ?>
      <h3 style="margin-top:18px">Mapa místa</h3>
      <div class="map">
        <?php $m = $event['map_embed_url'];
        if (stripos($m, '<iframe') !== false) { echo $m; }
        else { ?><iframe src="<?= e($m) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe><?php } ?>
      </div>
    <?php endif; ?>

    <!-- Vstupenky (akordeon) -->
   <h3 style="margin-top:18px">Vstupenky</h3>
<button id="btnOpenTicketsInline" class="btn small" type="button" style="margin-bottom:10px; cursor:pointer;">
  Koupit vstupenky
</button>

<div id="tickets-anchor"></div>

<div id="tickets-section">
  <div id="tickets-inner">

    <?php if ($renderGA): ?>
      <div id="ga-box" class="card" style="margin-bottom:12px">
        <div id="ga-list"></div>
        <small class="muted">te počet kusů pro jednotlivé typy a pokračujte k platbě.</small>
      </div>
    <?php endif; ?>

    <?php if ($renderSeats): ?>
      <div class="seat-area">
        <div id="seat-wrap" style="width:<?= (int)($schema['width'] ?? 800) ?>px;height:<?= (int)($schema['height'] ?? 500) ?>px"></div>
      </div>
      <div id="legend" class="legend"></div>
    <?php endif; ?>

    <div id="ccy-switch" style="display:flex;gap:10px;align-items:center;margin:10px 0 6px">
      <span class="muted">Měna:</span>
      <label><input type="radio" name="ccy" value="CZK" checked> CZK</label>
      <label><input type="radio" name="ccy" value="EUR"> EUR</label>
    </div>

    <div class="cart">
      <h3 style="margin:0 0 8px">Košík</h3>
      <ul id="cart-list" style="margin:0;padding-left:18px"></ul>
      <div class="cart-summary">
        <span class="muted">Vybráno: <span id="cart-count">0</span> míst</span>
        <span class="price">Celkem: <span id="cart-total">0 Kč</span></span>
      </div>
      <button id="btnCheckout" class="btn" disabled>Pokračovat k platbě</button>
    </div>

  </div>
</div>
  </article>
</section>
   <footer>© <?= date('Y') ?> — Všechna práva vyhrazena.</footer>
  </div>

  <!-- Lightbox overlay -->
  <div id="lb" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.86);z-index:9999;backdrop-filter:blur(2px)">
    <button id="lbClose" aria-label="Zavřít" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:12px;padding:8px 12px;cursor:pointer;position:absolute;top:16px;right:18px">×</button>
    <button id="lbPrev" aria-label="Předchozí" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:12px;padding:8px 12px;cursor:pointer;position:absolute;left:16px;top:50%;transform:translateY(-50%)">‹</button>
    <img id="lbImg" alt="" style="max-width:94vw;max-height:88vh;object-fit:contain;border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.35)" />
    <button id="lbNext" aria-label="Další" style="background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);color:#fff;border-radius:12px;padding:8px 12px;cursor:pointer;position:absolute;right:16px;top:50%;transform:translateY(-50%)">›</button>
  </div>

<script>
  // ===== Data z PHP =====
  const schema = <?= json_encode($schema, JSON_UNESCAPED_UNICODE) ?> || { width:800, height:480, tiers:{}, rows:{}, seats:[], tables:[] };
  const states = <?= json_encode($states) ?>;
  const gaTypes = <?= json_encode($gaTypesJs, JSON_UNESCAPED_UNICODE) ?> || [];

  // === MĚNA: stav a formátování ===
let selectedCCY = 'CZK';

function fmtMoney(v, ccy){
  try { return new Intl.NumberFormat('cs-CZ', { style:'currency', currency: ccy }).format(v); }
  catch { return `${Math.round(v)} ${ccy==='EUR' ? '€' : 'Kč'}`; }
}

// Vrátí cenu tieru v aktuálně vybrané měně (podpora nového i starého formátu)
function tierPriceInCCY(t){
  if (!t) return 0;

  // nový formát: { prices: { CZK: X, EUR: Y } }
  if (t.prices && typeof t.prices[selectedCCY] !== 'undefined') {
    const p = +t.prices[selectedCCY];
    return Number.isFinite(p) ? p : 0;
  }

  // starý formát: price_cents + currency
  const cur = (t.currency || 'CZK').toUpperCase();
  let p = Number(t.price_cents ?? 0);
  if (cur === 'CZK' && p >= 10000) p = Math.round(p / 100);
  return (cur === selectedCCY) ? (Number.isFinite(p) ? p : 0) : 0;
}


  // ID všech stolů (prefixy, které NEMAJÍ tvořit řadu)
  const tableIds = new Set((schema.tables || []).map(t => String(t.id || '')));

  // ===== Tier meta z JSONu =====
// === Tier meta (barva, jméno). Cenu neukládáme, bereme vždy podle selectedCCY ===
const PALETTE=['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#84cc16','#e11d48'];
const tiers = schema.tiers || {};
const tierKeys = Object.keys(tiers);
const tierMeta = {};
tierKeys.forEach((k,i)=>{
  const t = tiers[k]||{};
  tierMeta[k] = {
    code: k,
    name: t.name || k,
    color: t.color || PALETTE[i % PALETTE.length],
    // ceny se budou brát dynamicky přes tierPriceInCCY(t)
  };
});

// Cena sedadla podle vybraného tieru a měny
function priceForSeat(seat){
  const t = seat && seat.tier ? tiers[seat.tier] : null;
  return tierPriceInCCY(t);
}
  // ===== Seatmap render =====
  const wrap = document.getElementById('seat-wrap');
  if (wrap) {
    wrap.style.width=(schema.width||800)+'px';
    wrap.style.height=(schema.height||480)+'px';
    wrap.style.position='relative';
  }
  const SEATMAP_ENABLED = !!wrap;
  // === Tvar hlediště (schema.shape) ===
// Podporované typy: rect | round | polygon
(function renderShape(){
  if (!SEATMAP_ENABLED) return;
  const sh = schema.shape;
  if (!sh || (sh.type||'none') === 'none') return;

  const fill   = sh.fill   || '#eef2ff';
  const stroke = sh.stroke || '#c7d2fe';

  if (sh.type === 'rect' || sh.type === 'round') {
    const el = document.createElement('div');
    el.className = 'shape';
    el.style.left   = (sh.x || 0) + 'px';
    el.style.top    = (sh.y || 0) + 'px';
    el.style.width  = (sh.width  || (schema.width||800)) + 'px';
    el.style.height = (sh.height || (schema.height||480)) + 'px';
    el.style.background = fill;
    el.style.borderColor = stroke;
    if (sh.type === 'round') {
      el.style.borderRadius = (sh.radius || 24) + 'px';
    }
    wrap.appendChild(el);
    return;
  }

  if (sh.type === 'polygon' && Array.isArray(sh.points)) {
    // polygon vykreslíme pomocí SVG kvůli pěknému 'stroke'
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class','shape-svg');
    svg.setAttribute('viewBox', `0 0 ${schema.width||800} ${schema.height||480}`);
    svg.setAttribute('width',  schema.width || 800);
    svg.setAttribute('height', schema.height|| 480);

    const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
    const pts  = sh.points.map(p => `${p.x},${p.y}`).join(' ');
    poly.setAttribute('points', pts);
    poly.setAttribute('fill', fill);
    poly.setAttribute('stroke', stroke);
    poly.setAttribute('stroke-width', '1');

    svg.appendChild(poly);
    wrap.appendChild(svg);
  }
})();

  // Pódium (pokud je v JSONu)
if (SEATMAP_ENABLED && schema.stage && Number.isFinite(schema.stage.x)) {  const st = document.createElement('div');
  st.className = 'stage';
  st.style.left   = (schema.stage.x) + 'px';
  st.style.top    = (schema.stage.y) + 'px';
  st.style.width  = (schema.stage.width) + 'px';
  st.style.height = (schema.stage.height) + 'px';
  st.textContent  = schema.stage.label || 'Pódium';
  wrap.appendChild(st);
}

  // Košík
  const cart=new Set();
  const gaCart = new Map();          // GA: typeId -> qty
  const cartList=document.getElementById('cart-list');
  const btnCheckout=document.getElementById('btnCheckout');
  const elCount=document.getElementById('cart-count');
  const elTotal=document.getElementById('cart-total');

  // Sedadla
const seats = Array.isArray(schema.seats)?schema.seats:[];
const seatsById={}; seats.forEach(s=>seatsById[s.id]=s);

if (SEATMAP_ENABLED) {
  // Vykreslení sedadel (a pouze tady – žádné rowsAgg uvnitř!)
  seats.forEach(s=>{
    const dot=document.createElement('div');
    dot.className='seat-dot';
    const tm=tierMeta[s.tier]||{color:'#2563eb',name:s.tier||''};
    dot.style.background=tm.color;
    dot.style.left=(+s.x||0)+'px';
    dot.style.top =(+s.y||0)+'px';

    const state = states[s.id] || 'free';
    dot.dataset.seatId = s.id;
    dot.dataset.state = state;
    if (state === 'sold') dot.classList.add('sold');
    else if (state === 'held') dot.classList.add('held');

    dot.title = `${s.id}${tm.name?' • '+tm.name:''} • ${fmtMoney(priceForSeat(s), selectedCCY)}`;


    const numPart=(String(s.id).split('-')[1]||'').trim();
    const label=document.createElement('span');
    label.className='seat-label';
    label.textContent=numPart;
    dot.appendChild(label);

    dot.addEventListener('click',()=>{
      if(dot.dataset.state!=='free') return;
      const id=dot.dataset.seatId;
      if(cart.has(id)){
        cart.delete(id);
        dot.classList.remove('selected');
      } else {
        cart.add(id);
        dot.classList.add('selected');
      }
      renderCart();
    });

    wrap.appendChild(dot);
  });
  }
// ===== Seatmap: fit-to-container & center =====
(function(){
  const area  = document.querySelector('.seat-area');
  const inner = document.getElementById('seat-wrap');
  if(!area || !inner) return;

  function fitSeatWrap(){
    // reálné (nativní) rozměry seatmapy z JSONu
    const baseW = (schema && schema.width)  ? +schema.width  : inner.offsetWidth  || 800;
    const baseH = (schema && schema.height) ? +schema.height : inner.offsetHeight || 480;

    // šířka dostupného boxu (minus malé vnitřní okraje)
    const targetW = Math.max(200, area.clientWidth - 16);

    // poměr škálování (zachová aspekt)
    const scale = targetW / baseW;

    // aplikuj transform (škálujeme plátno, nepočítáme pozice sedadel)
    inner.style.transform = `scale(${scale})`;

    // nastav kontejneru dostatečnou výšku, aby se nic “nepřekrývalo”
    // (výška = škálovaná výška plátna + padding)
    area.style.minHeight = (baseH * scale + 24) + 'px';
  }

  // po načtení + při změně velikosti
  window.addEventListener('resize', () => { window.requestAnimationFrame(fitSeatWrap); });
  // první výpočet trochu odložíme, aby měl layout správné rozměry
  setTimeout(fitSeatWrap, 0);
})();
  // Agregace řad – MIMO smyčku sedadel, s ignorováním stolů
// Agregace řad – jen když je seatmapa
const rowsAgg = {};
if (SEATMAP_ENABLED) {
  seats.forEach(s=>{
    const rowKey = String(s.id).split('-')[0] || '';
    if (s._src === 'table' || tableIds.has(rowKey)) return;
    (rowsAgg[rowKey] ||= []).push({x: +s.x || 0, y: +s.y || 0});
  });
}

(function renderRowLabels(){
  if (!SEATMAP_ENABLED) return;

  const rowNames = (schema.rows && typeof schema.rows === 'object') ? schema.rows : {};
  const PAD = 38; // větší odstup od krajních sedadel
  const W = schema.width || wrap.clientWidth || 800;

  Object.entries(rowsAgg).forEach(([code, arr])=>{
    if (!arr || !arr.length) return;
    if (tableIds.has(code)) return; // prefix je ID stolu ⇒ přeskočit

    const seatsInRow = (schema.seats||[]).filter(s=>{
      if (s._src === 'table') return false;
      const k = String(s.id).split('-')[0] || '';
      return k === code;
    });
    if (!seatsInRow.length) return;

    const minX = Math.min(...seatsInRow.map(s => +s.x||0));
    const maxX = Math.max(...seatsInRow.map(s => +s.x||0));
    const avgY = Math.round(seatsInRow.reduce((a,s)=> a + (+s.y||0), 0) / seatsInRow.length);

    const leftX  = Math.max(PAD, minX - PAD);
    const rightX = Math.min(W - PAD, maxX + PAD);
    const text   = rowNames[code] || ('Řada ' + code);

    const leftLbl  = document.createElement('div');
    leftLbl.className = 'row-label';
    leftLbl.style.left = leftX + 'px';
    leftLbl.style.top  = avgY  + 'px';
    leftLbl.textContent = text;
    wrap.appendChild(leftLbl);

    const rightLbl  = document.createElement('div');
    rightLbl.className = 'row-label';
    rightLbl.style.left = rightX + 'px';
    rightLbl.style.top  = avgY  + 'px';
    rightLbl.textContent = text;
    wrap.appendChild(rightLbl);
  });
})();


  // Stoly ve veřejném detailu – bez textů (interní), volitelně s labely uprostřed
  const SHOW_TABLE_LABELS = false; // přepni na true, pokud chceš vidět např. "S1" uprostřed stolu

  (function renderTables(){
    if (!SEATMAP_ENABLED) return;
    (schema.tables || []).forEach(t=>{
      const r = +t.r || 10;
      const cx = +t.cx || 0;
      const cy = +t.cy || 0;

      const tableEl = document.createElement('div');
      tableEl.className = 'table-visual';
      tableEl.style.left   = (cx - r) + 'px';
      tableEl.style.top    = (cy - r) + 'px';
      tableEl.style.width  = (r * 2) + 'px';
      tableEl.style.height = (r * 2) + 'px';
      tableEl.title = ''; // žádný tooltip s ID
      wrap.appendChild(tableEl);

      if (SHOW_TABLE_LABELS) {
        const lab = document.createElement('div');
        lab.className = 'table-label';
        lab.textContent = t.id || '';
        tableEl.appendChild(lab);
      }
    });
  })();

  // Legenda tierů
// Legenda: vlevo tiery (ceny), vpravo stavové čipy
function renderLegendUI(){
  const lg=document.getElementById('legend');
  if(!lg) return;
  lg.innerHTML='';

  // --- Tiers (ceny) vlevo ---
  tierKeys.forEach(k=>{
    const t=tiers[k]; if(!t) return;
    const meta=tierMeta[k];
    const price = tierPriceInCCY(t);
    const chip=document.createElement('div');
    chip.className='chip tier';
    chip.innerHTML=
      `<span class="dot" style="background:${meta.color}"></span>
       <span class="name">${meta.name}</span>
       <span class="price">· ${price ? fmtMoney(price, selectedCCY) : ''}</span>`;
    lg.appendChild(chip);
  });

  // pružný oddělovač
  const spacer=document.createElement('div');
  spacer.className='spacer';
  lg.appendChild(spacer);

  // --- Stavové čipy vpravo ---
  const makeStateChip=(label, dotClass)=>{
    const c=document.createElement('div');
    c.className='chip state';
    c.innerHTML=`<span class="dot ${dotClass}"></span><span class="name">${label}</span>`;
    return c;
  };
  lg.appendChild(makeStateChip('Vybrané','state-selected'));
  lg.appendChild(makeStateChip('Drženo','state-held'));
  lg.appendChild(makeStateChip('Vyprodáno','state-sold'));
}
renderLegendUI();
renderGAList();
renderCart();
// inicializace po vykreslení sedadel
renderLegendUI();
function gaPriceFor(type){
  if(!type || !type.prices) return 0;
  const p = +type.prices[selectedCCY] || 0;
  return Number.isFinite(p) ? p : 0;
}

function renderGAList(){
  const box = document.getElementById('ga-list');
  if(!box) return;
  if(!gaTypes.length){ box.innerHTML='<div class="muted">Žádné typy vstupenek.</div>'; return; }

  const frag = document.createDocumentFragment();
  gaTypes.forEach(t=>{
    const row = document.createElement('div');
    row.style.cssText='display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid var(--border)';
    const price = gaPriceFor(t);
    const curQty = gaCart.get(t.id) || 0;

    row.innerHTML = `
      <span class="dot" style="width:12px;height:12px;border-radius:50%;background:${t.color}"></span>
      <div style="flex:1;min-width:0">
        <div style="font-weight:700">${t.name}</div>
        <div class="muted" style="font-size:13px">K dispozici: ${t.remaining}</div>
      </div>
      <div style="min-width:120px;text-align:right;font-weight:700">${price ? fmtMoney(price, selectedCCY) : ''}</div>
      <div style="display:flex;align-items:center;gap:6px">
        <button type="button" class="btn small" data-act="minus" data-id="${t.id}" ${curQty<=0?'disabled':''}>-</button>
        <input type="number" value="${curQty}" min="0" max="${t.remaining}" data-id="${t.id}" style="width:64px;padding:8px 10px;border:1px solid var(--border);border-radius:10px">
        <button type="button" class="btn small" data-act="plus" data-id="${t.id}" ${curQty>=t.remaining?'disabled':''}>+</button>
      </div>
    `;
    frag.appendChild(row);
  });
  box.innerHTML='';
  box.appendChild(frag);

  // listenery pro +/− a input
  box.querySelectorAll('button[data-act]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const id = +btn.getAttribute('data-id');
      const t  = gaTypes.find(x=>x.id===id);
      if(!t) return;
      const cur = gaCart.get(id)||0;
      const next = btn.getAttribute('data-act')==='plus' ? Math.min(cur+1, t.remaining) : Math.max(cur-1, 0);
      gaCart.set(id, next);
      if(next===0) gaCart.delete(id);
      renderGAList();
      renderCart();
    });
  });
  box.querySelectorAll('input[type="number"]').forEach(inp=>{
    inp.addEventListener('input', ()=>{
      const id = +inp.getAttribute('data-id');
      const t  = gaTypes.find(x=>x.id===id);
      if(!t) return;
      let v = parseInt(inp.value||'0',10);
      if(!Number.isFinite(v) || v<0) v=0;
      if(v>t.remaining) v=t.remaining;
      if(v===0) gaCart.delete(id); else gaCart.set(id,v);
      renderGAList();
      renderCart();
    });
  });
}
  // Košík render
function renderCart(){
  if(!cartList) return;
  cartList.innerHTML='';
  let total=0;

  // sedadla
  cart.forEach(id=>{
    const seat=seatsById[id]||{id};
    const p=priceForSeat(seat);
    total+=p;
    const meta=seat.tier&&tierMeta[seat.tier]?tierMeta[seat.tier]:null;
    const tierName=meta?meta.name:(seat.tier||'');
    const li=document.createElement('li');
    li.textContent=`${id}${tierName?' ('+tierName+')':''} – ${fmtMoney(p, selectedCCY)}`;
    cartList.appendChild(li);
  });

  // GA typy
  gaCart.forEach((qty, typeId)=>{
    const t = gaTypes.find(x=>x.id===typeId);
    if(!t || qty<=0) return;
    const unit = gaPriceFor(t);
    const sum = unit * qty;
    total += sum;
    const li=document.createElement('li');
    li.textContent = `${t.name} × ${qty} – ${fmtMoney(sum, selectedCCY)}`;
    cartList.appendChild(li);
  });

  if(elCount) elCount.textContent=String(cart.size + Array.from(gaCart.values()).reduce((a,b)=>a+b,0));
  if(elTotal) elTotal.textContent=fmtMoney(total, selectedCCY);
  if(btnCheckout) btnCheckout.disabled = (cart.size===0 && gaCart.size===0);
}


// Přepínač měny (radio CZK/EUR)
document.querySelectorAll('input[name="ccy"]').forEach(r=>{
  r.addEventListener('change', ()=>{
    selectedCCY = (r.value === 'EUR') ? 'EUR' : 'CZK';
       renderLegendUI();
    renderGAList();
    renderCart();
  });
});

  // Checkout (placeholder držení)
if (btnCheckout) {
  btnCheckout.addEventListener('click', async ()=>{
    const seatsSel = Array.from(cart);
    const gaSel = Array.from(gaCart, ([type_id, qty]) => ({ type_id, qty }));

    const res = await fetch('/public/hold.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({
        slug: <?= json_encode($slug) ?>,
        seats: seatsSel,
        ga: gaSel,
        ccy: selectedCCY
      })
    });
    const data = await res.json().catch(()=>({ok:false}));
    if (data && data.ok) {
      alert('Držení / kontrola proběhla, pokračujeme na checkout (placeholder).');
      // location.href = data.redirect || '/checkout'; // až budeš mít
    } else {
      alert('Některé položky už nejsou dostupné. Aktualizujte výběr.');
      location.reload();
    }
  });
}


  // Lightbox galerie
  (function(){
    const thumbs=[...document.querySelectorAll('.thumbs img')];
    if(!thumbs.length) return;
    const lb=document.getElementById('lb'), img=document.getElementById('lbImg'),
          p=document.getElementById('lbPrev'), n=document.getElementById('lbNext'), c=document.getElementById('lbClose');
    let i=0;
    const srcs=thumbs.map(t=>t.getAttribute('data-full')||t.src);
    function openAt(k){ i=(k+srcs.length)%srcs.length; img.src=srcs[i]; lb.style.display='flex'; document.body.style.overflow='hidden'; }
    function close(){ lb.style.display='none'; img.src=''; document.body.style.overflow=''; }
    function prev(){ openAt(i-1);} function next(){ openAt(i+1);}
    thumbs.forEach(t=>t.addEventListener('click',()=>{ openAt(parseInt(t.getAttribute('data-gidx')||'0',10)); }));
    lb.addEventListener('click',e=>{ if(e.target===lb) close(); });
    c.addEventListener('click',close); p.addEventListener('click',prev); n.addEventListener('click',next);
    window.addEventListener('keydown',e=>{ if(lb.style.display!=='flex') return; if(e.key==='Escape') close(); else if(e.key==='ArrowLeft') prev(); else if(e.key==='ArrowRight') next(); });
  })();

  // Fallback cover error
  document.querySelectorAll('.cover img').forEach(img=>{
    img.addEventListener('error',()=>{
      img.style.display='none';
      const d=document.createElement('div');
      d.style.cssText='display:flex;align-items:center;justify-content:center;height:100%;color:#777';
      d.textContent='Obrázek nelze načíst';
      img.parentElement.appendChild(d);
    });
  });
 // === Akordeon „Koupit vstupenky“ – toggle open/close + změna textu ===
(function(){
  const section = document.getElementById('tickets-section');
  const anchor  = document.getElementById('tickets-anchor');
  const btnTop  = document.getElementById('btnOpenTicketsTop');
  const btnInl  = document.getElementById('btnOpenTicketsInline');

const hasSeats = (schema && Array.isArray(schema.seats) && schema.seats.length>0);
const TXT_OPEN  = hasSeats ? 'Zobrazit vstupenky' : 'Vybrat vstupenky';
const TXT_CLOSE = hasSeats ? 'Schovat vstupenky'  : 'Zavřít';
  const TXT_TOP_OPEN  = 'Zobrazit vstupenky';
  const TXT_TOP_CLOSE = 'Schovat vstupenky';

  function setBtnTexts(isOpen){
    if (btnInl) btnInl.textContent = isOpen ? TXT_CLOSE : TXT_OPEN;
    if (btnTop) btnTop.textContent = isOpen ? TXT_TOP_CLOSE : TXT_TOP_OPEN;
    if (btnInl) btnInl.setAttribute('aria-expanded', String(isOpen));
    if (btnTop) btnTop.setAttribute('aria-expanded', String(isOpen));
  }

  function toggle(openAndScroll=false){
    const willOpen = !section.classList.contains('open');
    section.classList.toggle('open', willOpen);
    setBtnTexts(willOpen);
    if (willOpen && openAndScroll) {
      setTimeout(()=>{ anchor.scrollIntoView({ behavior:'smooth', block:'start' }); }, 50);
    }
  }

  if (btnTop) btnTop.addEventListener('click', ()=> toggle(true));
  if (btnInl) btnInl.addEventListener('click', ()=> toggle(false));

  // start: zavřeno
  setBtnTexts(false);

  // pokud je hash, otevři a sjeď
  if (location.hash === '#tickets') {
    section.classList.add('open');
    setBtnTexts(true);
    setTimeout(()=> anchor.scrollIntoView({behavior:'smooth'}), 30);
  }
})();


</script>
</body>
</html>