<?php
require_once __DIR__.'/../inc/db.php';
require_once __DIR__.'/../inc/auth.php';
ensure_admin();

$event_id = $_GET['event'] ?? '';
if (!$event_id) { header('Location:/admin/'); exit; }

$st = db()->prepare("SELECT * FROM events WHERE id=?");
$st->execute([$event_id]);
$event = $st->fetch(PDO::FETCH_ASSOC);
if (!$event) { http_response_code(404); die("Akce nenalezena"); }

// Načti poslední verzi seatmapy
$sm = db()->prepare("SELECT schema_json FROM event_seatmaps WHERE event_id=? ORDER BY version DESC LIMIT 1");
$sm->execute([$event_id]);
$seatmap = $sm->fetch(PDO::FETCH_ASSOC);

if ($seatmap) {
  $decoded = json_decode($seatmap['schema_json'], true);
  if (!is_array($decoded)) $decoded = [];
  $decoded['width']     = $decoded['width']     ?? 900;
  $decoded['height']    = $decoded['height']    ?? 520;
  $decoded['seats']     = $decoded['seats']     ?? [];
  $decoded['tiers']     = $decoded['tiers']     ?? [];
  $decoded['rows']      = $decoded['rows']      ?? new stdClass(); // kód->label
  $decoded['rows_meta'] = $decoded['rows_meta'] ?? new stdClass(); // kód->{ seats, tier, price_cents, color, num_dir }
  $decoded['stage']     = $decoded['stage']     ?? ['x'=>60,'y'=>60,'width'=>780,'height'=>28,'label'=>'Pódium'];
  $decoded['shape']     = $decoded['shape']     ?? null;            // {type:'rect'|'round'|'polygon', ...}
  $decoded['tables']    = $decoded['tables']    ?? [];              // [{id,cx,cy,r,seat_count,tier,seat_ids[] }]
  $json = json_encode($decoded, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
} else {
  $json = json_encode([
    'width'=>900,'height'=>520,
    'tiers'=>[
      'A'=>['name'=>'Přední řady','price_cents'=>690,'currency'=>'CZK','color'=>'#2563eb'],
      'B'=>['name'=>'Zadní řady', 'price_cents'=>490,'currency'=>'CZK','color'=>'#10b981']
    ],
    'rows'=>(object)['A'=>'Řada A','B'=>'Řada B'],
    'rows_meta'=>(object)[
      'A'=>['seats'=>19,'tier'=>'A','price_cents'=>690,'color'=>'#2563eb','num_dir'=>'L'],
      'B'=>['seats'=>22,'tier'=>'B','price_cents'=>490,'color'=>'#10b981','num_dir'=>'L']
    ],
    'stage'=>['x'=>60,'y'=>60,'width'=>780,'height'=>28,'label'=>'Pódium'],
    'shape'=>['type'=>'round','x'=>40,'y'=>110,'width'=>820,'height'=>360,'radius'=>28,'fill'=>'#eef2ff','stroke'=>'#c7d2fe'],
    'tables'=>[],
    'seats'=>[]
  ], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
}

$err = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    $raw = $_POST['schema_json'] ?? '';
    if ($raw==='') throw new RuntimeException('Formulář neposlal žádná data.');
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    // Sanitizace minim
    $data['width']  = isset($data['width'])  ? (int)$data['width']  : 900;
    $data['height'] = isset($data['height']) ? (int)$data['height'] : 520;
    $data['seats']  = isset($data['seats'])  && is_array($data['seats']) ? array_values($data['seats']) : [];
    $data['tiers']  = isset($data['tiers'])  && is_array($data['tiers']) ? $data['tiers'] : [];
    if (!isset($data['rows']) || !is_array($data['rows'])) $data['rows'] = new stdClass();
    if (!isset($data['rows_meta']) || !is_array($data['rows_meta'])) $data['rows_meta'] = new stdClass();
    if (isset($data['stage']) && !is_array($data['stage'])) $data['stage'] = null;
    if (isset($data['shape']) && !is_array($data['shape'])) $data['shape'] = null;
    if (!isset($data['tables']) || !is_array($data['tables'])) $data['tables'] = [];

    // verze = max+1
    $vStmt = db()->prepare("SELECT COALESCE(MAX(version),0)+1 v FROM event_seatmaps WHERE event_id=?");
    $vStmt->execute([$event_id]);
    $v = (int)($vStmt->fetch(PDO::FETCH_ASSOC)['v'] ?? 1);

    $ins = db()->prepare("INSERT INTO event_seatmaps(event_id,version,schema_json) VALUES (?,?,?)");
    $ins->execute([$event_id, $v, json_encode($data, JSON_UNESCAPED_UNICODE)]);

    // regen runtime
    $del = db()->prepare("DELETE FROM seats_runtime WHERE event_id=?");
    $del->execute([$event_id]);

    $insSeat = db()->prepare("INSERT INTO seats_runtime(event_id, seat_id, state) VALUES (?,?, 'free')");
    foreach ($data['seats'] as $s) { if (!empty($s['id'])) $insSeat->execute([$event_id, $s['id']]); }
    // nastav režim akce na "seatmap"
$setMode = db()->prepare("
  UPDATE events
     SET seating_mode = 'seatmap'
   WHERE (id = ? OR id = UNHEX(REPLACE(?, '-', '')))
   LIMIT 1
");
$setMode->execute([$eventId, $eventId]); // $eventId = ID té editované akce

    header('Location: '. strtok($_SERVER['REQUEST_URI'], '?') . '?event='.urlencode($event_id));
    exit;
  } catch(Throwable $ex){
    http_response_code(400);
    $err = 'Uložení selhalo: '. htmlspecialchars($ex->getMessage());
  }
}
?>
<?php
$admin_event_title = $event['title'] ?? '';
$admin_back_href   = '/admin/event_detail.php?id=' . rawurlencode((string)($event['id'] ?? $id));
$admin_show_back   = true;

include __DIR__.'/_header.php';?>
<h1>Sedadla – <?= htmlspecialchars($event['title'] ?? 'Akce') ?></h1>
<?php if(!empty($err)): ?><div class="alert alert-danger"><?= $err ?></div><?php endif; ?>

<form method="post" class="editor-grid">
  <aside class="left">
<nav class="tabs" role="tablist" aria-label="Editor sedadel">
  <button type="button" class="tab active" data-tab="tools">
    <i class="fa-solid fa-screwdriver-wrench"></i><span>Nástroje</span>
  </button>
  <button type="button" class="tab" data-tab="stage">
    <i class="fa-solid fa-sliders"></i><span>Pódium</span>
  </button>
  <button type="button" class="tab" data-tab="shape">
    <i class="fa-regular fa-object-group"></i><span>Tvar</span>
  </button>
  <button type="button" class="tab" data-tab="rows">
    <i class="fa-solid fa-grip-lines"></i><span>Řady</span>
  </button>
  <button type="button" class="tab" data-tab="tables">
    <i class="fa-solid fa-chair"></i><span>Stoly</span>
  </button>
  <button type="button" class="tab" data-tab="tiers">
    <i class="fa-solid fa-palette"></i><span>Tiery</span>
  </button>
  <button type="button" class="tab" data-tab="json">
    <i class="fa-solid fa-code"></i><span>JSON</span>
  </button>
</nav>


    <section class="panel tab-panel" id="tab-tools">
      <div class="panel-title">🔧 Generátory a nástroje</div>
      <div class="tools-grid">
        <div>
          <label>Šířka (px)<input type="number" id="w" value="900"></label>
          <label>Výška (px)<input type="number" id="h" value="520"></label>
          <button type="button" class="btn outline" id="applySize">
  <i class="fa-regular fa-square"></i><span>Nastavit plochu</span>
</button>

<button type="button" class="btn outline" id="rebuildFromRows">
  <i class="fa-solid fa-rotate-right"></i><span>Přegenerovat řady</span>
</button>

        </div>
        <div>
          <label>Start X / Y<input type="number" id="startX" value="120"> <input type="number" id="startY" value="160"></label>
          <label>Krok X / Y<input type="number" id="stepX" value="20"> <input type="number" id="stepY" value="25"></label>
        </div>
        <div>
          <label>Vnitřní okraje (L / R)<input type="number" id="contentLeft" value="20"> <input type="number" id="contentRight" value="20"></label>
          <label><input type="checkbox" id="centerRows" checked> Zarovnat každou řadu na střed</label>
          <label>Odsazení popisků řad (px)<input type="number" id="rowLabelOffset" value="25"></label>
        </div>
        <div>
          <label>Snap (px) pro přesuny<input type="number" id="snapPx" value="8"></label>
          <label><input type="checkbox" id="enableDrag" checked> Povolit drag & drop sedadel</label>
          <label>
  <input type="checkbox" id="deleteMode">  Režim mazání sedadel</label>
        </div>
        <div>
          <label>Svislé odsazení pod pódiem<input type="number" id="offsetFromStage" value="50"></label>
          <button type="button" class="btn outline" id="rebuildFromRows">Přegenerovat z řad</button>
        </div>
      </div>
    </section>

    <section class="panel tab-panel hidden" id="tab-stage">
      <div class="panel-title">🎚️ Pódium</div>
      <div class="tools-grid">
        <div>
          <label>Label<input type="text" id="stageLabel" value="Pódium"></label>
          <label>X / Y<input type="number" id="stageX" value="60"> <input type="number" id="stageY" value="60"></label>
        </div>
        <div>
          <label>Šířka / Výška<input type="number" id="stageW" value="780"> <input type="number" id="stageH" value="28"></label>
          <button type="button" class="btn outline" id="saveStage">
  <i class="fa-regular fa-floppy-disk"></i><span>Uložit pódium</span>
</button>

        </div>
      </div>
    </section>

    <section class="panel tab-panel hidden" id="tab-shape">
      <div class="panel-title">⬛ Tvar hlediště</div>
      <div class="tools-grid">
        <div>
          <label>Typ tvaru
            <select id="shapeType">
              <option value="none">žádný</option>
              <option value="rect">obdélník</option>
              <option value="round">oblý obdélník</option>
              <option value="polygon">polygon (editor)</option>
            </select>
          </label>
          <label>Fill / Stroke<input type="text" id="shapeFill" value="#eef2ff"> <input type="text" id="shapeStroke" value="#c7d2fe"></label>
        </div>
        <div>
          <label>X / Y<input type="number" id="shapeX" value="40"> <input type="number" id="shapeY" value="110"></label>
          <label>Šířka / Výška<input type="number" id="shapeW" value="820"> <input type="number" id="shapeH" value="360"></label>
          <label>Radius (u round)<input type="number" id="shapeR" value="28"></label>
        </div>
        <div>
          <button type="button" class="btn outline" id="toggleShapeEdit">
  <i class="fa-regular fa-pen-to-square"></i><span>Editovat tvar v náhledu</span>
</button>
<button type="button" class="btn outline" id="saveShape">
  <i class="fa-regular fa-floppy-disk"></i><span>Uložit tvar</span>
</button>

        </div>
      </div>
    </section>

    <section class="panel tab-panel hidden" id="tab-rows">
      <div class="panel-title">🧱 Editor řad</div>
      <div class="rowtable-wrap">
        <table class="rowtable" id="rowTable">
          <thead>
            <tr>
              <th style="width:70px">Kód</th>
              <th style="width:220px">Název</th>
              <th style="width:110px">Sedadla</th>
              <th style="width:120px">Tier</th>
              <th style="width:130px">Cena (Kč)</th>
              <th style="width:80px">Barva</th>
              <th style="width:120px">Číslování</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="rowtools">
        <button type="button" class="btn" id="addRow">
  <i class="fa-solid fa-plus"></i><span>Přidat řadu</span>
</button>
<button type="button" class="btn outline" id="rowsRebuild">
  <i class="fa-solid fa-rotate-right"></i><span>Přegenerovat řady</span>
</button>

      </div>
    </section>

    <section class="panel tab-panel hidden" id="tab-tables">
      <div class="panel-title">🪑 Stoly</div>
      <div class="tools-grid">
        <div>
          <label>Stůl ID<input type="text" id="tblId" value="T1"></label>
          <label>Střed X / Y<input type="number" id="tblCx" value="450"> <input type="number" id="tblCy" value="300"></label>
        </div>
        <div>
          <label>Poloměr stolu<input type="number" id="tblR" value="10"></label>
          <label>Sedadla u stolu<input type="number" id="tblCount" value="8"></label>
        </div>
        <div>
          <label>Tier (barva & cena beru z tiers)<input type="text" id="tblTier" value="A"></label>
          <label>Počáteční úhel (°)<input type="number" id="tblStartAng" value="-90"></label>
          <button type="button" class="btn outline" id="addTable">
  <i class="fa-solid fa-plus"></i><span>Přidat stůl</span>
</button>

        </div>
      </div>
      <div class="rowtable-wrap" style="margin-top:10px">
        <table class="rowtable" id="tablesList">
          <thead><tr><th style="width:120px">ID</th><th style="width:90px">Tier</th><th style="width:120px">R</th><th style="width:140px">Pozice</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </section>

    <section class="panel tab-panel hidden" id="tab-tiers">
      <div class="panel-title">🎨 Tiery (barvy & ceny)</div>
      <div class="rowtable-wrap">
        <table class="rowtable" id="tierTable">
          <thead>
            <tr>
              <th style="width:90px">Kód</th>
              <th style="width:220px">Název</th>
              <th style="width:120px">Cena CZK</th>
<th style="width:120px">Cena EUR</th>
              <th style="width:100px">Barva</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <div class="rowtools">
        <button type="button" class="btn" id="addTier">
  <i class="fa-solid fa-plus"></i><span>Přidat tier</span>
</button>

      </div>
    </section>

    <section class="panel tab-panel hidden" id="tab-json">
      <div class="panel-title">{} JSON</div>
      <label class="label">Seatmap JSON</label>
      <textarea name="schema_json" id="schema" rows="24"><?= htmlspecialchars($json) ?></textarea>
      <div class="actions">
        <button type="submit" class="btn primary">Uložit novou verzi</button>
        <a href="/admin/" class="btn outline">Zpět do administrace</a>
        <span id="valMsg2" class="muted" style="margin-left:8px"></span>
      </div>
    </section>
  </aside>

  <section class="right">
    <div class="preview-wrap sticky">
      <h3>Náhled</h3>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
  <span class="muted">Měna náhledu:</span>
  <label><input type="radio" name="ccyPreview" value="CZK" checked> CZK</label>
  <label><input type="radio" name="ccyPreview" value="EUR"> EUR</label>
</div>
      <div id="seat-preview" class="seat-preview"></div>
      <div id="legend" class="legend"></div>
      <div id="stats" class="stats muted"></div>
    </div>
    <div id="errBox" class="errbox" style="display:none"></div>
  </section>
</form>

<style>
/* ===== Layout editoru – vzdušnější, full-width friendly ===== */
main.container {
      max-width: 1400px !important;
      margin: 22px auto;
      padding: 0 14px;
    }
.editor-grid{
  display:grid;
  grid-template-columns: 360px minmax(0,1fr);
  gap:24px;
  align-items:start;
}
@media (min-width:1400px){
  .editor-grid{ grid-template-columns: 380px minmax(0,1fr); gap:28px; }
}
@media (max-width:1100px){
  .editor-grid{ grid-template-columns:1fr; }
  .right .sticky{ position:static; }
}

/* Right column – sticky náhled, ale s limitem na výšku obrazovky */
.right .sticky{
  position:sticky;
  top:18px;
}

/* ===== Tabs ===== */
.tabs{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  row-gap:6px;
  margin-bottom:10px;
}
.tab{
  display:inline-flex; align-items:center; gap:8px;
  padding:9px 12px;
  border-radius:10px;
  border:1px solid var(--border);
  background:#f8faff;
  cursor:pointer;
  font-weight:600;
  color:#0b1220;
  transition:background .2s ease, border-color .2s ease, color .2s ease;
}
.tab i{ font-size:14px; line-height:1; }
.tab.active{
  border-color:#c7d2fe;
  background:#eef2ff;
  color:#111827;
}
.tab-panel.hidden{ display:none; }

/* ===== Panely vlevo ===== */
.panel{
  border:1px solid var(--border);
  border-radius:12px;
  padding:14px;
  margin-bottom:12px;
  background:#fbfcff;
}
.panel-title{
  font-weight:700;
  margin-bottom:10px;
}

.tools-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(160px,1fr));
  gap:12px 14px;
}
.tools-grid label{
  display:block;
  font-size:13px;
  color:#374151;
  margin-bottom:6px;
}
.tools-grid input,
.tools-grid select{
  width:80%;
  margin: 5px 0 0;
  padding:9px 10px;
  border:1px solid var(--border);
  border-radius:8px;
  background:#fff;
  font-size:14px;
}

/* ===== Tabulky (Řady, Tiery, Stoly) ===== */
.rowtable-wrap{
  overflow:auto;
  max-height:360px;
  border:1px solid var(--border);
  border-radius:12px;
  background:#fff;
}
.rowtable{
  width:100%;
  border-collapse:collapse;
  table-layout:fixed;
}
.rowtable th, .rowtable td{
  border-bottom:1px solid var(--border);
  padding:8px 10px;
  font-size:13px;
}
.rowtable th{
  background:#f6f8ff;
  position:sticky; top:0; z-index:1;
  color:#334155;
  font-weight:700;
}
.rowtable input[type="text"],
.rowtable input[type="number"],
.rowtable select{
  width:100%;
  padding:6px 8px;
  border:1px solid var(--border);
  border-radius:6px;
  font-size:14px;
}
.rowtable input[type="color"]{
  width:38px; height:32px;
  border:none; background:transparent;
}
.rowtools{
  margin-top:10px;
  display:flex; gap:8px; flex-wrap:wrap;
}

/* ===== Náhled (pravý panel) – plátno vždy viditelné ===== */
.preview-wrap{
  background:#fbfcff;
  border:1px solid var(--border);
  border-radius:12px;
  padding:14px;
}

/* DŮLEŽITÉ:
   - wrap (#seat-preview) dostává výšku z JS (d.height)
   - šířka je 100% rodiče → na úzkých displejích zmenší šířku
   - aby se nic „neuseklo“, zapínáme scrollbars
*/
.seat-preview{
  position:relative;
  background:#f7f8fb;
  border:1px dashed var(--border);
  border-radius:16px;
  box-shadow:inset 0 1px 0 rgba(3,14,38,.02);
  width:100%;
  max-height: calc(100vh - 220px); /* náhled respektuje výšku okna */
  overflow: auto;                   /* místo hidden → scrolluj */
}

/* Pokud chceš ještě „víc prostoru“ pro vyšší headery, můžeš navýšit -220px na -260px apod. */

/* ===== Vykreslené prvky v náhledu ===== */
.shape{
  position:absolute; z-index:1;
  border:1px solid transparent;
  pointer-events:none;
}
.stage{
  position:absolute;
  border:2px dashed #111827;
  background:rgba(17,24,39,.06);
  color:#111827;
  display:flex; align-items:center; justify-content:center;
  border-radius:8px; font-weight:700; z-index:3;
}
.row-label{
  position:absolute;
  transform:translate(-50%,-50%);
  font-size:12px; font-weight:700; color:#374151;
  pointer-events:none; white-space:nowrap; max-width:200px; overflow:hidden; text-overflow:ellipsis; z-index:4;
}
.row-label.left{ text-align:right; }
.row-label.right{ text-align:left; }

.seat-dot{
  position:absolute;
  width:18px; height:18px;
  border-radius:50%;
  transform:translate(-50%,-50%);
  cursor:grab;
  display:flex; align-items:center; justify-content:center;
  font-size:10px; font-weight:700; color:#fff;
  box-shadow:0 2px 8px rgba(37,99,235,.2);
  z-index:4;
}
.seat-dot.dragging{ cursor:grabbing; opacity:.95; }
.seat-dot.deleting{ outline:2px dashed #ef4444; box-shadow:0 0 0 2px rgba(239,68,68,.35); }
.seat-label{ pointer-events:none; line-height:1; }

/* legenda a statistiky pod náhledem */
.legend{
  display:flex; flex-wrap:wrap; gap:10px; margin-top:10px;
}
.legend .chip{
  display:inline-flex; align-items:center; gap:8px;
  border:1px solid var(--border);
  background:#fff;
  border-radius:999px;
  padding:6px 10px;
  box-shadow:var(--shadow);
}
.legend .dot{ width:12px; height:12px; border-radius:50%; }
.legend .name{ font-weight:600; color:#0b1220; }
.legend .price{ color:#5b677a; font-size:13px; }

.stats{ margin-top:8px; }

/* Chybové boxy, alerty */
.errbox{
  margin-top:10px;
  padding:10px;
  border:1px solid #fecaca;
  background:#fef2f2;
  color:#7f1d1d;
  border-radius:12px;
}
.alert{
  margin:10px 0; padding:10px 12px;
  border:1px solid #fee2e2; background:#fef2f2;
  color:#7f1d1d; border-radius:10px;
}

/* ===== Tlačítka – modrý styl ===== */
.btn{
  display:inline-flex; align-items:center; gap:8px;
  padding:9px 12px;
  border-radius:10px;
  border:1px solid var(--accent);
  background:var(--accent);
  color:#fff;
  font-weight:600;
  cursor:pointer;
  transition: background .2s ease, border-color .2s ease, color .2s ease, transform .1s ease;
}
.btn:hover{ background:var(--accent-600); border-color:var(--accent-600); }
.btn:active{ transform: translateY(1px); }

.btn.outline{
  background:#fff;
  color:#2563eb;
  border-color:#2563eb;
}
.btn.outline:hover{
  background:#2563eb;
  color:#fff;
}

/* Textové drobnosti */
.muted{ color:#5b677a; font-size:13px; }

/* ===== Tooltip kurzorový ===== */
#tip{
  position:absolute; z-index:50; pointer-events:none;
  background:#111827; color:#fff;
  border-radius:8px; padding:6px 8px; font-size:12px;
  box-shadow:0 10px 30px rgba(0,0,0,.25);
  opacity:0; transform:translate(-50%,-8px);
  transition:opacity .12s ease;
}
#tip .sub{ opacity:.8; }

/* ===== Edit handles ===== */
.handle{
  position:absolute; width:10px; height:10px;
  background:#111827; border:2px solid #fff; border-radius:2px;
  z-index:5; cursor:nwse-resize;
}
.handle.move{ cursor:move; border-radius:50%; }

/* ===== Stoly ===== */
.table{
  position:absolute;
  border:2px solid #111827;
  border-radius:50%;
  background:rgba(17,24,39,.05);
  z-index:2;
}
.table.dragging{ opacity:.85; }

/* ===== Drobné UI vychytávky ===== */
/* jemnější scrollbar v náhledu (podporované prohlížeče) */
.seat-preview::-webkit-scrollbar{ height:10px; width:10px; }
.seat-preview::-webkit-scrollbar-thumb{ background:#c7d2fe; border-radius:999px; }
.seat-preview::-webkit-scrollbar-track{ background:#eef2ff; border-radius:999px; }

</style>

<div id="tip"><div id="tipMain"></div><div class="sub" id="tipSub"></div></div>

<script>
document.addEventListener('DOMContentLoaded', () => {
/* =========================
   SEATMAP EDITOR – FIXED
   ========================= */

const schemaEl = document.getElementById('schema') || document.querySelector('textarea[name="schema_json"]');
if(!schemaEl){ console.error('Chybí textarea #schema'); return; }

const wrap   = document.getElementById('seat-preview');
const legend = document.getElementById('legend');
const stats  = document.getElementById('stats');
const errBox = document.getElementById('errBox');
const tip    = document.getElementById('tip');
const tipMain= document.getElementById('tipMain');
const tipSub = document.getElementById('tipSub');

const rowTableBody   = document.querySelector('#rowTable tbody');
const tierTableBody  = document.querySelector('#tierTable tbody');
const tablesListBody = document.querySelector('#tablesList tbody');
const valMsg2        = document.getElementById('valMsg2');

/* ---- Ovládací prvky z Tools / Stage / Shape / Tables ---- */
const wEl=document.getElementById('w'), hEl=document.getElementById('h');
const startXEl=document.getElementById('startX'), startYEl=document.getElementById('startY');
const stepXEl=document.getElementById('stepX'), stepYEl=document.getElementById('stepY');
const contentLeftEl=document.getElementById('contentLeft'), contentRightEl=document.getElementById('contentRight');
const centerRowsEl=document.getElementById('centerRows');
const rowLabelOffsetEl=document.getElementById('rowLabelOffset');
const snapPxEl=document.getElementById('snapPx');
const enableDragEl=document.getElementById('enableDrag');
const deleteModeEl=document.getElementById('deleteMode');
const offsetFromStageEl=document.getElementById('offsetFromStage');

const stageX=document.getElementById('stageX'), stageY=document.getElementById('stageY'), stageW=document.getElementById('stageW'), stageH=document.getElementById('stageH'), stageLabel=document.getElementById('stageLabel');

const shapeType=document.getElementById('shapeType'), shapeX=document.getElementById('shapeX'), shapeY=document.getElementById('shapeY'), shapeW=document.getElementById('shapeW'), shapeH=document.getElementById('shapeH'), shapeR=document.getElementById('shapeR'), shapeFill=document.getElementById('shapeFill'), shapeStroke=document.getElementById('shapeStroke');
const toggleShapeEditBtn=document.getElementById('toggleShapeEdit'); let editingShape=false;

const tblId=document.getElementById('tblId'), tblCx=document.getElementById('tblCx'), tblCy=document.getElementById('tblCy'), tblR=document.getElementById('tblR'), tblCount=document.getElementById('tblCount'), tblTier=document.getElementById('tblTier'), tblStartAng=document.getElementById('tblStartAng');

const PALETTE = ['#2563eb','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#84cc16','#e11d48'];

/* ---------- Helpers: JSON / UI ---------- */
function readSchema(){ try{ return JSON.parse(schemaEl.value); }catch(e){ return null; } }
function writeSchema(obj){ schemaEl.value = JSON.stringify(obj, null, 2); }
function ensure(obj){
  obj = obj && typeof obj==='object' ? obj : {};
  if (!Array.isArray(obj.seats)) obj.seats = [];
  if (!obj.tiers || typeof obj.tiers!=='object') obj.tiers = {};
  if (!obj.rows  || typeof obj.rows !=='object') obj.rows  = {};
  if (!obj.rows_meta || typeof obj.rows_meta !=='object') obj.rows_meta = {};
  if (!Array.isArray(obj.tables)) obj.tables = [];
  obj.width  = Number.isFinite(obj.width)  ? obj.width  : 900;
  obj.height = Number.isFinite(obj.height) ? obj.height : 520;
  return obj;
}
function priceKcFromTier(t){
  if(!t) return 0;
  let p=Number(t.price_cents??0);
  const cur=(t.currency||'CZK').toUpperCase();
  if(cur==='CZK' && p>=10000) p=Math.round(p/100);
  return isFinite(p)?p:0;
}
function tierColor(tiers, code){
  const t = tiers && tiers[code]; 
  return (t && t.color) ? t.color : '#9aa3b2';
}
function fmtCZK(v){ try{ return new Intl.NumberFormat('cs-CZ',{style:'currency',currency:'CZK',maximumFractionDigits:0}).format(v);}catch{ return Math.round(v)+' Kč'; } }
// === MĚNA – stav + formátovače ===
let selectedCCY = 'CZK';

function fmtMoney(v, ccy){
  try { return new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: ccy }).format(v); }
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

function showTipNearCursor(ev, main, sub){ if(!tip) return; tipMain.textContent=main; tipSub.textContent=sub||''; const rect=wrap.getBoundingClientRect(); let tx=ev.clientX-rect.left+12; let ty=ev.clientY-rect.top+12; const maxX=(rect.width-10), maxY=(rect.height-10); if(tx>maxX) tx=maxX; if(ty>maxY) ty=maxY; if(tx<10) tx=10; if(ty<10) ty=10; tip.style.left=tx+'px'; tip.style.top=ty+'px'; tip.style.opacity=1; }
function hideTip(){ if(tip) tip.style.opacity=0; }
function snap(v, step){ step = +step||0; return step>0 ? Math.round(v/step)*step : v; }

/* ---------- Tab logika (ponecháno) ---------- */
const tabs = document.querySelectorAll('.tab');
const panels = document.querySelectorAll('.tab-panel');
function setActiveTab(name){
  tabs.forEach(b=> b.classList.toggle('active', b.dataset.tab===name));
  panels.forEach(p=> p.classList.toggle('hidden', p.id!=='tab-'+name));
  try{ localStorage.setItem('seatmapActiveTab', name);}catch{}
}
let initialTab='tools'; try{ const s=localStorage.getItem('seatmapActiveTab'); if(s) initialTab=s; }catch{}
setActiveTab(initialTab);
tabs.forEach(btn=> btn.addEventListener('click', (e)=>{ e.preventDefault(); e.stopPropagation(); setActiveTab(btn.dataset.tab); }));

/* ---------- ŘADY: tabulka, sběr & render ---------- */
function addRowRow(code, name, seats, tier, price, color, num_dir){
  const tr=document.createElement('tr');
  tr.innerHTML = `
<td><input type="text"   class="i-code"  value="${code||''}" placeholder="A"></td>
<td><input type="text"   class="i-name"  value="${name||''}" placeholder="Řada A"></td>
<td><input type="number" class="i-seats" value="${seats??10}" min="0"></td>
<td><input type="text"   class="i-tier"  value="${tier||''}" placeholder="A"></td>
<td><input type="number" class="i-price" value="${price??0}" min="0"></td>
<td><input type="color"  class="i-color" value="${color||'#2563eb'}"></td>
<td>
  <select class="i-numdir">
    <option value="L" ${num_dir==='L'?'selected':''}>zleva</option>
    <option value="R" ${num_dir==='R'?'selected':''}>zprava</option>
  </select>
</td>
<td><button type="button" class="btn outline btn-del-table" data-id="{{ID}}">
  <i class="fa-regular fa-trash-can"></i><span>Smazat</span>
</button>
</td>`;
  rowTableBody.appendChild(tr);
}
function renderRowTable(data){
  rowTableBody.innerHTML='';
  const entries = Object.entries(data.rows_meta||{});
  if (!entries.length){
    // Alespoň 1 prázdný řádek pro pohodlí
    addRowRow('', 'Řada ', 10, 'A', 0, '#2563eb', 'L');
    return;
  }
  for (const [code, meta] of entries){
    addRowRow(
      code,
      (data.rows && data.rows[code])||('Řada '+code),
      meta.seats||10,
      meta.tier||code,
      meta.price_cents??0,
      meta.color||'#2563eb',
      meta.num_dir||'L'
    );
  }
}
function collectRowsFromTable(){
  const rows_meta = {};
  const rows = {};
  rowTableBody.querySelectorAll('tr').forEach(tr=>{
    const code = tr.querySelector('.i-code').value.trim();
    if(!code) return;
    const name = tr.querySelector('.i-name').value.trim() || ('Řada '+code);
    const seats= +tr.querySelector('.i-seats').value || 0;
    const tier = tr.querySelector('.i-tier').value.trim() || code;
    const price= +tr.querySelector('.i-price').value || 0;
    const color= tr.querySelector('.i-color').value || '#2563eb';
    const num_dir = tr.querySelector('.i-numdir').value || 'L';
    rows_meta[code] = { seats, tier, price_cents: price, color, num_dir };
    rows[code] = name;
  });
  return { rows_meta, rows };
}

/* ---------- Tiery: tabulka ---------- */
function addTierRow(code, name, priceCZK, priceEUR, color){
  const tr=document.createElement('tr');
  tr.innerHTML=`
<td><input class="t-code"  value="${code||''}" placeholder="A"></td>
<td><input class="t-name"  value="${name||''}" placeholder="Název"></td>
<td><input type="number" class="t-price-czk" value="${priceCZK||0}" min="0"></td>
<td><input type="number" class="t-price-eur" value="${priceEUR||0}" min="0" step="1"></td>
<td><input type="color"  class="t-color" value="${color||'#2563eb'}"></td>
<td><button type="button" class="btn outline t-del">
  <i class="fa-regular fa-trash-can"></i><span>Smazat</span>
</button></td>`;
  tierTableBody.appendChild(tr);
}

function priceKcFromTier(t){
  // ZPĚTNÁ KOMPATIBILITA
  if (!t) return 0;
  if (t.prices && typeof t.prices.CZK !== 'undefined') return +t.prices.CZK || 0;
  let p = Number(t.price_cents ?? 0);
  const cur = (t.currency||'CZK').toUpperCase();
  if (cur==='CZK' && p>=10000) p=Math.round(p/100);
  return isFinite(p)?p:0;
}
function priceEurFromTier(t){
  if (t && t.prices && typeof t.prices.EUR !== 'undefined') return +t.prices.EUR || 0;
  return 0;
}
function renderTierTable(data){
  tierTableBody.innerHTML='';
  const entries = Object.entries(data.tiers||{});
  if (!entries.length){
    addTierRow('A','Přední řady', 690, 29, '#2563eb');
    addTierRow('B','Zadní řady',  490, 21, '#10b981');
    return;
  }
  for (const [code, t] of entries){
    addTierRow(code, t.name||code, priceKcFromTier(t), priceEurFromTier(t), t.color||'#2563eb');
  }
}

function collectTiersFromUI(){
  const tiers={};
  tierTableBody.querySelectorAll('tr').forEach(tr=>{
    const code=(tr.querySelector('.t-code').value||'').trim();
    if(!code) return;
    const name=(tr.querySelector('.t-name').value||code).trim();
    const pCZK= +tr.querySelector('.t-price-czk').value || 0;
    const pEUR= +tr.querySelector('.t-price-eur').value || 0;
    const color= tr.querySelector('.t-color').value || '#2563eb';
    tiers[code] = { name, prices:{ CZK:pCZK, EUR:pEUR }, color };
  });
  return tiers;
}


/* ---------- Stoly: tabulka ---------- */
function renderTablesList(data){
  tablesListBody.innerHTML='';
  (data.tables||[]).forEach(t=>{
    const tr=document.createElement('tr');
    tr.innerHTML=`<td>${t.id}</td><td>${t.tier||''}</td><td>${t.r}</td><td>${Math.round(t.cx)}, ${Math.round(t.cy)}</td><td style="text-align:right"><button type="button" class="btn outline btn-del-table" data-id="${t.id}">Smazat</button></td>`;
    tablesListBody.appendChild(tr);
  });
}

/* ---------- Ukládací pomocník ---------- */
function updateSchema(mutator, repaint=true){
  let d=readSchema(); d=ensure(d);
  mutator(d);
  writeSchema(d);
  if (valMsg2) valMsg2.textContent='Uloženo do editoru (nezapomeň Uložit verzi)';
  if (repaint) paint();
}

/* ---------- REBUILD z řad (opraveno) ---------- */
function doRebuild(){
  const sxBase = +startXEl.value||120;
  const sy     = +startYEl.value||160;
  const stepX  = +stepXEl.value||20;
  const stepY  = +stepYEl.value||25;
  const off    = +offsetFromStageEl.value||50;
  const contentLeft  = +contentLeftEl.value||20;
  const contentRight = +contentRightEl.value||20;
  const centerRows   = !!centerRowsEl.checked;

  const {rows_meta} = collectRowsFromTable();
  const codes = Object.keys(rows_meta);
  if (!codes.length){ alert('Nejprve přidej nějaké řady.'); return; }

  updateSchema(d=>{
    // Stage offset
    let curY = sy;
    if (d.stage && Number.isFinite(d.stage.y) && Number.isFinite(d.stage.height)){
      const syBase = d.stage.y + d.stage.height + off;
      curY = Math.max(sy, syBase);
    }

    const seats=[];
    const usableW=(d.width||900)-contentLeft-contentRight;

    codes.forEach(code=>{
      const meta = rows_meta[code];
      const count = +meta.seats||0;
      if (count<=0){ curY += stepY; return; }

      const rowWidth = (count-1)*stepX;
      let rowStartX = sxBase;
      if (centerRows){
        rowStartX = contentLeft + Math.round((usableW - rowWidth)/2);
      }

      for(let i=1;i<=count;i++){
        const visualIndex = i;
        const num = (meta.num_dir==='R') ? (count - i + 1) : i;
        const x = rowStartX + (visualIndex-1)*stepX;
        seats.push({ id:`${code}-${num}`, x, y: curY, tier: (meta.tier||code), _row: code });
      }
      curY += stepY;
    });

    // sedadla + auto-doplnění tierů z rows_meta
    const preservedTableSeats = (d.seats || []).filter(s => s._src === 'table');
const rebuiltRowSeats = seats.map(s => ({ ...s, _src: 'row' }));
d.seats = [...preservedTableSeats, ...rebuiltRowSeats];
    d.tiers = d.tiers || {};
    Object.entries(rows_meta).forEach(([code,m])=>{
      const name = (d.rows && d.rows[code]) || code;
      d.tiers[m.tier || code] = { name, price_cents: m.price_cents||0, currency:'CZK', color: m.color||'#2563eb' };
    });

    // rows / rows_meta aktualizuj z UI
    const col = collectRowsFromTable();
    d.rows = col.rows; d.rows_meta = col.rows_meta;
  });
}

/* ---------- PŘEHLEDY / LEGENDA ---------- */
// === LEGENDA – ceny v aktuálně vybrané měně ===
function rebuildLegend(d){
  legend.innerHTML = '';
  const frag = document.createDocumentFragment();

  Object.entries(d.tiers||{}).forEach(([code, t])=>{
    const price = tierPriceInCCY(t); // !!! měna z radií
    const chip = document.createElement('div');
    chip.className = 'chip';
    chip.innerHTML =
      `<span class="dot" style="background:${t.color||'#9aa3b2'}"></span>
       <span class="name">${t.name||code}</span>
       <span class="price">${price ? fmtMoney(price, selectedCCY) : ''}</span>`;
    frag.appendChild(chip);
  });

  legend.appendChild(frag);
}

// === STATS – počty + součet v aktuálně vybrané měně ===
function rebuildStats(d){
  const seats = Array.isArray(d.seats) ? d.seats : [];
  const tiers = d.tiers || {};

  let totalSeats = 0;
  let totalValue = 0;

  const byTierCount = {};
  const byTierValue = {};

  seats.forEach(s=>{
    totalSeats++;
    const t = tiers[s.tier];
    const price = tierPriceInCCY(t); // !!! měna z radií

    byTierCount[s.tier] = (byTierCount[s.tier] || 0) + 1;
    byTierValue[s.tier] = (byTierValue[s.tier] || 0) + price;
    totalValue += price;
  });

  const parts = [`Sedadla: ${totalSeats}`];
  Object.keys(byTierCount).forEach(k=>{
    parts.push(`${k}: ${byTierCount[k]} ks`);
  });
  parts.push(`Součet (ceny): ${fmtMoney(totalValue, selectedCCY)}`);

  stats.textContent = parts.join(' • ');
}


/* ---------- PREVIEW / PAINT (komplet opraveno) ---------- */
function clearPreview(){
  wrap.innerHTML='';
  wrap.style.width = '100%';
  // výšku nastavíme fixně, vnitřní plátno (relative) se přizpůsobí podle d.height
}
function paint(){
  const data = ensure(readSchema());
  try{
    errBox.style.display='none'; errBox.textContent='';

    // rozměry
    clearPreview();
    wrap.style.height = (data.height||520)+'px';
    wrap.style.position = 'relative';

    /* SHAPE (volitelné) */
    if (data.shape && data.shape.type && data.shape.type!=='none'){
      const sh = document.createElement('div');
      sh.className='shape';
      sh.style.left = (data.shape.x||0)+'px';
      sh.style.top  = (data.shape.y||0)+'px';
      sh.style.width  = (data.shape.width || (data.width||900))+'px';
      sh.style.height = (data.shape.height|| (data.height||520))+'px';
      sh.style.background = data.shape.fill || '#eef2ff';
      sh.style.borderColor = data.shape.stroke || '#c7d2fe';
      if (data.shape.type==='round'){
        sh.style.borderRadius = (data.shape.radius||24)+'px';
      } else if (data.shape.type==='polygon' && Array.isArray(data.shape.points)){
        const pts = data.shape.points.map(p=>`${p.x}px ${p.y}px`).join(',');
        sh.style.left='0px'; sh.style.top='0px';
        sh.style.width=(data.width||900)+'px'; sh.style.height=(data.height||520)+'px';
        sh.style.clipPath = `polygon(${pts})`;
      }
      wrap.appendChild(sh);

      // jednoduchá editace u rect/round
      if (editingShape && (data.shape.type==='rect' || data.shape.type==='round')){
        addRectHandlesForShape(sh, data.shape);
      }
      // polygon body v edit režimu
      if (editingShape && data.shape.type==='polygon'){
        addPolygonHandlesForShape(data.shape);
      }
    }

    /* STAGE */
    if (data.stage && Number.isFinite(data.stage.x)){
      const st = document.createElement('div');
      st.className='stage';
      st.style.left = data.stage.x+'px';
      st.style.top  = data.stage.y+'px';
      st.style.width  = data.stage.width+'px';
      st.style.height = data.stage.height+'px';
      st.textContent = data.stage.label || 'Pódium';
      wrap.appendChild(st);
    }

    /* SEDADLA + čísla + drag */
    const snapPx = +snapPxEl.value||0;
    const enableDrag = !!enableDragEl.checked;
    const delMode = !!deleteModeEl.checked;

    const byRow = {}; // pro popisky řad
    (data.seats||[]).forEach(s=>{
      const el = document.createElement('div');
      el.className='seat-dot';
      el.style.left = (s.x)+'px';
      el.style.top  = (s.y)+'px';
      el.style.background = tierColor(data.tiers, s.tier);
      el.title = s.id || '';

      const span = document.createElement('div');
      span.className='seat-label';
      // vypisuj poslední číslo z ID (po pomlčce)
      const m = String(s.id||'').match(/-(\d+)$/);
      span.textContent = m ? m[1] : '';
      el.appendChild(span);

      if (delMode){ el.classList.add('deleting'); }

      // drag & drop sedadla
      if (enableDrag && !delMode){
        let startX=0, startY=0, oX=0, oY=0;
        const onMove=(ev)=>{
          const rect=wrap.getBoundingClientRect();
          let nx = ev.clientX-rect.left - startX + oX;
          let ny = ev.clientY-rect.top  - startY + oY;
          nx = snap(nx, snapPx);
          ny = snap(ny, snapPx);
          el.style.left = nx+'px';
          el.style.top  = ny+'px';
          showTipNearCursor(ev, s.id||'', `${Math.round(nx)}, ${Math.round(ny)}`);
        };
        const onUp=(ev)=>{
          document.removeEventListener('mousemove',onMove);
          document.removeEventListener('mouseup',onUp);
          el.classList.remove('dragging');
          hideTip();
          // persist do JSON
          updateSchema(d=>{
            const idx = (d.seats||[]).findIndex(ss=> String(ss.id)===String(s.id));
            if (idx>-1){
              const rect=wrap.getBoundingClientRect();
              const cur = wrap.querySelectorAll('.seat-dot');
              // pozici bereme přímo ze stylu elementu
              const nx = parseFloat(el.style.left);
              const ny = parseFloat(el.style.top);
              d.seats[idx].x = nx;
              d.seats[idx].y = ny;
            }
          }, false);
        };
        el.addEventListener('mousedown',(ev)=>{
          if(ev.button!==0) return;
          ev.preventDefault();
          const rect=wrap.getBoundingClientRect();
          startX=ev.clientX-rect.left; startY=ev.clientY-rect.top;
          oX = s.x; oY = s.y;
          el.classList.add('dragging');
          document.addEventListener('mousemove',onMove);
          document.addEventListener('mouseup',onUp);
        });
      }

      // smazání sedadla v režimu mazání
      if (delMode){
        el.addEventListener('click', ()=>{
          updateSchema(d=>{
            d.seats = (d.seats||[]).filter(ss => String(ss.id)!==String(s.id));
          });
        });
      }

      wrap.appendChild(el);

      if (s._row){
        (byRow[s._row] ||= []).push({x:s.x, y:s.y});
      } else {
        // fallback – odvodíme kód z ID do byRow
        const r = String(s.id||'').split('-')[0] || '';
        (byRow[r] ||= []).push({x:s.x, y:s.y});
      }
    });

    /* POPISKY ŘAD (z UI rows/rows_meta nebo z byRow) */
    const rowOffset = +rowLabelOffsetEl.value||25;
    const rowsMeta = data.rows_meta||{};
    const rowsNames= data.rows||{};
    Object.keys(byRow).forEach(code=>{
      const pts = byRow[code];
      if (!pts || !pts.length) return;
      const midY = Math.round(pts.reduce((a,p)=>a+p.y,0)/pts.length);
      const left  = document.createElement('div');
      const right = document.createElement('div');
      left.className='row-label left';
      right.className='row-label right';
      left.style.left  = (Math.max(0, (data.width||900) * 0.02))+'px';
      left.style.top   = (midY)+'px';
      right.style.left = ((data.width||900) - Math.max(0,(data.width||900)*0.02))+'px';
      right.style.top  = (midY)+'px';
      const text = rowsNames[code] || ('Řada '+code);
      left.textContent = text;
      right.textContent= text;
      wrap.appendChild(left);
      wrap.appendChild(right);
    });

    /* STOLY */
    (data.tables||[]).forEach(t=>{
      const el = document.createElement('div');
      el.className='table';
      el.style.left = (t.cx - t.r)+'px';
      el.style.top  = (t.cy - t.r)+'px';
      el.style.width  = (t.r*2)+'px';
      el.style.height = (t.r*2)+'px';
      el.title = t.id;

      // drag stolu s persistem + přepočet sedadel u stolu
      let startX=0, startY=0, oCx=0, oCy=0;
      el.addEventListener('mousedown',(ev)=>{
        if(!enableDragEl.checked || deleteModeEl.checked) return;
        if(ev.button!==0) return;
        ev.preventDefault();
        const rect=wrap.getBoundingClientRect();
        startX=ev.clientX-rect.left; startY=ev.clientY-rect.top; oCx=t.cx||0; oCy=t.cy||0;
        el.classList.add('dragging');
        const onMove=(ev2)=>{
          const rect=wrap.getBoundingClientRect();
          let nCx = ev2.clientX-rect.left - startX + oCx;
          let nCy = ev2.clientY-rect.top  - startY + oCy;
          nCx = snap(nCx, +snapPxEl.value||0);
          nCy = snap(nCy, +snapPxEl.value||0);
          el.style.left=(nCx-t.r)+'px';
          el.style.top =(nCy-t.r)+'px';
          showTipNearCursor(ev2, t.id, `${Math.round(nCx)}, ${Math.round(nCy)}`);
          // průběžně persist do JSONu a posunout sedadla u stolu
          updateSchema(d=>{
            const T = (d.tables||[]).find(x=>x.id===t.id);
            if(T){ T.cx=nCx; T.cy=nCy; }
            (d.seats||[]).forEach(s=>{
              if (Array.isArray(t.seat_ids) && t.seat_ids.includes(s.id)){
                const ang=parseFloat(s._ang||0);
                s.x = nCx + Math.cos(ang)*(t.r+18);
                s.y = nCy + Math.sin(ang)*(t.r+18);
              }
            });
          }, false);
        };
        const onUp=()=>{
          document.removeEventListener('mousemove',onMove);
          document.removeEventListener('mouseup',onUp);
          el.classList.remove('dragging');
          hideTip();
          paint();
        };
        document.addEventListener('mousemove',onMove);
        document.addEventListener('mouseup',onUp);
      });

      // smazání stolu + jeho sedadel v režimu mazání
      if (deleteModeEl.checked){
        el.addEventListener('click', ()=>{
          updateSchema(d=>{
            const T = (d.tables||[]).find(x=>x.id===t.id);
            const rm = new Set(T?.seat_ids||[]);
            d.tables = (d.tables||[]).filter(x=>x.id!==t.id);
            d.seats  = (d.seats ||[]).filter(s=> !rm.has(s.id) && !String(s.id||'').startsWith(t.id+'-'));
          });
        });
      }

      wrap.appendChild(el);
    });

    rebuildLegend(data);
    rebuildStats(data);
    renderTablesList(data);

  } catch(ex){
    errBox.style.display='block';
    errBox.textContent = 'Chyba v náhledu: ' + ex.message;
  }
}

/* ---- Edit pomůcky pro shape (rect/round/polygon) ---- */
function addRectHandlesForShape(el, s){
  // jednoduchý move handle
  const mv=document.createElement('div');
  mv.className='handle move';
  mv.style.left=(s.x + (s.width/2) - 5)+'px';
  mv.style.top =(s.y - 16)+'px';
  wrap.appendChild(mv);
  let sx=0,sy=0,ox=0,oy=0;
  const onMM=(ev)=>{
    const rect=wrap.getBoundingClientRect();
    const dx=(ev.clientX-rect.left)-sx;
    const dy=(ev.clientY-rect.top )-sy;
    const nx = ox+dx, ny=oy+dy;
    el.style.left=nx+'px'; el.style.top=ny+'px';
    updateSchema(d=>{ if(d.shape){ d.shape.x=nx; d.shape.y=ny; } }, false);
  };
  const onMU=()=>{ document.removeEventListener('mousemove',onMM); document.removeEventListener('mouseup',onMU); };
  mv.addEventListener('mousedown',(ev)=>{
    ev.preventDefault();
    const rect=wrap.getBoundingClientRect();
    sx=ev.clientX-rect.left; sy=ev.clientY-rect.top; ox=s.x; oy=s.y;
    document.addEventListener('mousemove',onMM); document.addEventListener('mouseup',onMU);
  });

  // resize handle (spodní pravý)
  const br=document.createElement('div');
  br.className='handle';
  br.style.left=(s.x+s.width-5)+'px';
  br.style.top =(s.y+s.height-5)+'px';
  wrap.appendChild(br);
  let rsx=0,rsy=0,ow=0,oh=0;
  const onR=(ev)=>{
    const rect=wrap.getBoundingClientRect();
    const dx=(ev.clientX-rect.left)-rsx;
    const dy=(ev.clientY-rect.top )-rsy;
    const nw=Math.max(20,ow+dx), nh=Math.max(20,oh+dy);
    el.style.width=nw+'px'; el.style.height=nh+'px';
    updateSchema(d=>{ if(d.shape){ d.shape.width=nw; d.shape.height=nh; } }, false);
  };
  const onRU=()=>{ document.removeEventListener('mousemove',onR); document.removeEventListener('mouseup',onRU); };
  br.addEventListener('mousedown',(ev)=>{
    ev.preventDefault();
    const rect=wrap.getBoundingClientRect();
    rsx=ev.clientX-rect.left; rsy=ev.clientY-rect.top; ow=s.width; oh=s.height;
    document.addEventListener('mousemove',onR); document.addEventListener('mouseup',onRU);
  });
}
function addPolygonHandlesForShape(shape){
  if(!Array.isArray(shape.points)) shape.points=[];
  shape.points.forEach((p,idx)=>{
    const h=document.createElement('div');
    h.className='handle';
    h.style.left=(p.x-5)+'px';
    h.style.top =(p.y-5)+'px';
    h.style.cursor='move';
    wrap.appendChild(h);
    let sx=0,sy=0,ox=0,oy=0;
    const onMM=(ev)=>{
      const rect=wrap.getBoundingClientRect();
      const nx=(ev.clientX-rect.left)-sx+ox;
      const ny=(ev.clientY-rect.top )-sy+oy;
      h.style.left=(nx-5)+'px'; h.style.top=(ny-5)+'px';
      updateSchema(d=>{ if(d.shape && d.shape.type==='polygon'){ d.shape.points[idx]={x:nx,y:ny}; } }, false);
      paint();
    };
    const onMU=()=>{ document.removeEventListener('mousemove',onMM); document.removeEventListener('mouseup',onMU); };
    h.addEventListener('mousedown',(ev)=>{
      ev.preventDefault();
      const rect=wrap.getBoundingClientRect();
      sx=ev.clientX-rect.left; sy=ev.clientY-rect.top; ox=p.x; oy=p.y;
      document.addEventListener('mousemove',onMM);
      document.addEventListener('mouseup',onMU);
    });
  });

  // doubleclick do plochy = přidat bod
  wrap.addEventListener('dblclick', (ev)=>{
    if(!editingShape) return;
    const d=ensure(readSchema());
    if(!d.shape || d.shape.type!=='polygon') return;
    const rect=wrap.getBoundingClientRect();
    const x=ev.clientX-rect.left, y=ev.clientY-rect.top;
    d.shape.points.push({x,y});
    writeSchema(d);
    paint();
  });
}

/* ---------- INIT UI Z JSONU (klíčová oprava) ---------- */
function initUIFromSchema(){
  const d = ensure(readSchema());
  // Tools defaults z JSONu
  if (wEl) wEl.value = d.width||900;
  if (hEl) hEl.value = d.height||520;
  if (stageX) { stageX.value = d.stage?.x ?? 60; stageY.value = d.stage?.y ?? 60; stageW.value = d.stage?.width ?? 780; stageH.value = d.stage?.height ?? 28; stageLabel.value = d.stage?.label ?? 'Pódium'; }
// === Přepínač měny (radio) – přerenderuje legendu i stats ===
document.querySelectorAll('input[name="ccyPreview"]').forEach(r=>{
  r.addEventListener('change', ()=>{
    selectedCCY = (r.value === 'EUR') ? 'EUR' : 'CZK';
    paint(); // překreslí preview, legendu i stats
  });
});

  // Shape defaults
  if (shapeType){
    const t = d.shape?.type || 'none';
    shapeType.value = t;
    shapeX.value = d.shape?.x ?? 40;
    shapeY.value = d.shape?.y ?? 110;
    shapeW.value = d.shape?.width  ?? 820;
    shapeH.value = d.shape?.height ?? 360;
    shapeR.value = d.shape?.radius ?? 28;
    shapeFill.value   = d.shape?.fill   ?? '#eef2ff';
    shapeStroke.value = d.shape?.stroke ?? '#c7d2fe';
  }

  // Tabulky: Řady / Tiery / Stoly
  renderRowTable(d);
  renderTierTable(d);
  renderTablesList(d);

  // Náhled
  paint();
}

/* ---------- Akce / Listenery ---------- */
// velikost plochy
const btnApplySize = document.getElementById('applySize');
if(btnApplySize) btnApplySize.addEventListener('click', ()=>{
  const w=+wEl.value||900, h=+hEl.value||520;
  updateSchema(d=>{ d.width=w; d.height=h; });
});

// stage
const btnSaveStage = document.getElementById('saveStage');
if(btnSaveStage) btnSaveStage.addEventListener('click', ()=>{
  const sx=+stageX.value||60, sy=+stageY.value||60, sw=+stageW.value||600, sh=+stageH.value||30, sl=stageLabel.value||'Pódium';
  updateSchema(d=>{ d.stage={ x:sx, y:sy, width:sw, height:sh, label:sl }; });
});

// shape edit
const btnToggleShape = document.getElementById('toggleShapeEdit');
if(btnToggleShape) btnToggleShape.addEventListener('click', ()=>{
  editingShape=!editingShape;
  btnToggleShape.textContent = editingShape? '✅ Ukončit editaci tvaru' : '✏️ Editovat tvar v náhledu';
  paint();
});
const btnSaveShape = document.getElementById('saveShape');
if(btnSaveShape) btnSaveShape.addEventListener('click', ()=>{
  const type = shapeType.value;
  if (type==='none'){ updateSchema(d=>{ d.shape=null; }); return; }
  if (type==='polygon'){
    const d=ensure(readSchema());
    if(!d.shape || d.shape.type!=='polygon'){
      d.shape = {type:'polygon', points:[{x:40,y:140},{x:860,y:140},{x:820,y:460},{x:80,y:460}], fill:shapeFill.value||'#eef2ff', stroke:shapeStroke.value||'#c7d2fe'};
    }
    d.shape.fill=shapeFill.value||'#eef2ff'; d.shape.stroke=shapeStroke.value||'#c7d2fe';
    writeSchema(d); paint();
  } else if (type==='round'){
    updateSchema(d=>{ d.shape={ type:'round', x:+shapeX.value||0, y:+shapeY.value||0, width:+shapeW.value||0, height:+shapeH.value||0, radius:+shapeR.value||24, fill:shapeFill.value||'#eef2ff', stroke:shapeStroke.value||'#c7d2fe' }; });
  } else { // rect
    updateSchema(d=>{ d.shape={ type:'rect', x:+shapeX.value||0, y:+shapeY.value||0, width:+shapeW.value||0, height:+shapeH.value||0, fill:shapeFill.value||'#eef2ff', stroke:shapeStroke.value||'#c7d2fe' }; });
  }
});

// Rebuild (Tools i Rows)
const btnRebuildMain = document.getElementById('rebuildFromRows'); if(btnRebuildMain) btnRebuildMain.addEventListener('click', doRebuild);
const btnRebuildRows = document.getElementById('rowsRebuild');     if(btnRebuildRows) btnRebuildRows.addEventListener('click', doRebuild);

// Řady – přidání, mazání, live sync (!!! oprava)
const btnAddRow = document.getElementById('addRow');
if(btnAddRow) btnAddRow.addEventListener('click', ()=>{
  addRowRow('', 'Řada ', 10, 'A', 0, '#2563eb','L');
  const v=collectRowsFromTable();
  updateSchema(d=>{ d.rows_meta=v.rows_meta; d.rows=v.rows; }, false);
});
if (rowTableBody){
  rowTableBody.addEventListener('click', (e)=>{
    if(e.target.classList.contains('btn-del')){
      e.target.closest('tr').remove();
      const v=collectRowsFromTable();
      updateSchema(d=>{ d.rows_meta=v.rows_meta; d.rows=v.rows; }, false);
    }
  });
  let rowDeb;
  rowTableBody.addEventListener('input', ()=>{
    clearTimeout(rowDeb);
    rowDeb=setTimeout(()=>{
      const v=collectRowsFromTable();
      updateSchema(d=>{ d.rows_meta=v.rows_meta; d.rows=v.rows; }, false);
    }, 120);
  });
}

// Tiery – přidání, mazání, live sync
const btnAddTier = document.getElementById('addTier');
if(btnAddTier) btnAddTier.addEventListener('click', ()=>{ addTierRow('', '', 0, 'CZK', '#2563eb'); });
if (tierTableBody){
  tierTableBody.addEventListener('click', (e)=>{
    if(e.target.classList.contains('t-del')){
      e.target.closest('tr').remove();
      const tiers=collectTiersFromUI();
      updateSchema(d=>{ d.tiers=tiers; }, false);
    }
  });
  let tDeb;
  tierTableBody.addEventListener('input', ()=>{
    clearTimeout(tDeb);
    tDeb=setTimeout(()=>{
      const tiers=collectTiersFromUI();
      updateSchema(d=>{ d.tiers=tiers; }, false);
    }, 120);
  });
}

// Stoly – přidání + seznam + smazání
const btnAddTable = document.getElementById('addTable');
if (btnAddTable) btnAddTable.addEventListener('click', ()=>{
  let id=(tblId.value||'T1').trim();
  const cx=+tblCx.value||300, cy=+tblCy.value||300, r=+tblR.value||10, cnt=+tblCount.value||6, tier=(tblTier.value||'A').trim(), startAng=(+tblStartAng.value||-90)*(Math.PI/180);
  updateSchema(d=>{
    const existingIds = new Set((d.tables||[]).map(t=>t.id));
    if(existingIds.has(id)){ let i=2; while(existingIds.has(id+"_"+i)) i++; id = id+"_"+i; }
    if(!d.tiers[tier]) d.tiers[tier] = { name:`${tier}`, price_cents:0, currency:'CZK', color: PALETTE[Math.floor(Math.random()*PALETTE.length)] };
    const seat_ids=[];
    for(let i=0;i<cnt;i++){
      const ang = startAng + (i*2*Math.PI/cnt);
      const sx = cx + Math.cos(ang)*(r+18);
      const sy = cy + Math.sin(ang)*(r+18);
      const sid = `${id}-${i+1}`;
      seat_ids.push(sid);
      d.seats.push({ id:sid, x:sx, y:sy, tier, _ang: ang, _src: 'table' });
    }
    (d.tables|| (d.tables=[])).push({ id, cx, cy, r, seat_count:cnt, tier, seat_ids });
  });
});
if (tablesListBody){
  tablesListBody.addEventListener('click', (e)=>{
    if(e.target.classList.contains('btn-del-table')){
      const id=e.target.dataset.id;
      updateSchema(d=>{
        const T = (d.tables||[]).find(tt=>tt.id===id);
        const rm = new Set(); if (T && Array.isArray(T.seat_ids)) T.seat_ids.forEach(s=> rm.add(s));
        d.tables = (d.tables||[]).filter(tt=> tt.id!==id);
        d.seats = (d.seats||[]).filter(s=> !rm.has(s.id) && !String(s.id||'').startsWith(id+'-'));
      });
    }
  });
}

/* Live repaint při ruční editaci JSONu */
let tmr; schemaEl.addEventListener('input', ()=>{ clearTimeout(tmr); tmr=setTimeout(()=>{ paint(); }, 160); });
deleteModeEl.addEventListener('change', () => paint());
enableDragEl.addEventListener('change', () => paint());
snapPxEl.addEventListener('change', () => paint());
rowLabelOffsetEl.addEventListener('change', () => paint());

/* Inicializace UI z JSONu (!!! klíčové) */
initUIFromSchema();

});
</script>

<?php include __DIR__.'/_footer.php'; ?>
