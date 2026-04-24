<?php
declare(strict_types=1);
// ── Engine endpoint ──────────────────────────────────────────────────────────
if (isset($_GET['_engine'])) {
    header('Content-Type: application/json');
    $raw  = file_get_contents('php://input');
    $post = json_decode($raw ?: '', true) ?? $_POST;
    try {
        $steps = (int)($post['steps'] ?? 120);
        $integ = (string)($post['integrator'] ?? 'rk4');
        echo json_encode(pendulumBatch($post, $steps, $integ));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Access check ─────────────────────────────────────────────────────────────
// Walk up from simulations/paid/ to find config.php at the document root.
$configPath = dirname(__DIR__, 2) . '/config.php';
$isPaid = false;
if (file_exists($configPath)) {
    require_once $configPath;
    $currentUser = getCurrentUser();
    if ($currentUser) {
        try {
            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT status FROM purchases
                 WHERE user_id = ? AND product_id = 'sim-pendulum'
                   AND status IN ('paid','demo')
                 LIMIT 1"
            );
            $stmt->execute([$currentUser['id']]);
            $isPaid = (bool)$stmt->fetch();
        } catch (Throwable $e) {
            error_log('sim-pendulum.php access check: ' . $e->getMessage());
        }
    }
}

// Redirect unpaid visitors to the trial file.
if (!$isPaid) {
    $trialUrl = str_replace('/paid/', '/trial/', $_SERVER['REQUEST_URI'] ?? '');
    header('Location: ' . $trialUrl);
    exit;
}

// ── Physics engine functions ─────────────────────────────────────────────────
function hamiltonianPendulum(float $theta, float $omega, float $L, float $g): float {
    return 0.5 * $L * $L * $omega * $omega + $g * $L * (1.0 - cos($theta));
}

function rk4Pendulum(float $th, float $om, float $t, float $dt,
                     float $L, float $g, float $b, float $dAmp, float $dFreq): array {
    $deriv = static function (float $th, float $om, float $t)
        use ($L, $g, $b, $dAmp, $dFreq): array {
        return [$om, -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t)];
    };
    [$k1t, $k1o] = $deriv($th, $om, $t);
    [$k2t, $k2o] = $deriv($th + 0.5*$dt*$k1t, $om + 0.5*$dt*$k1o, $t + 0.5*$dt);
    [$k3t, $k3o] = $deriv($th + 0.5*$dt*$k2t, $om + 0.5*$dt*$k2o, $t + 0.5*$dt);
    [$k4t, $k4o] = $deriv($th + $dt*$k3t, $om + $dt*$k3o, $t + $dt);
    return [
        $th + ($dt / 6.0) * ($k1t + 2*$k2t + 2*$k3t + $k4t),
        $om + ($dt / 6.0) * ($k1o + 2*$k2o + 2*$k3o + $k4o),
    ];
}

function symplecticPendulum(float $th, float $om, float $t, float $dt,
                             float $L, float $g, float $b, float $dAmp, float $dFreq): array {
    $alpha_half = -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t);
    $om_half    = $om + 0.5 * $dt * $alpha_half;
    $th_new     = $th + $dt * $om_half;
    $alpha_new  = -($g / $L) * sin($th_new) - $b * $om_half + $dAmp * cos($dFreq * ($t + $dt));
    $om_new     = $om_half + 0.5 * $dt * $alpha_new;
    return [$th_new, $om_new];
}

function eulerPendulum(float $th, float $om, float $t, float $dt,
                       float $L, float $g, float $b, float $dAmp, float $dFreq): array {
    $alpha = -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t);
    return [$th + $dt * $om, $om + $dt * $alpha];
}

function pendulumBatch(array $p, int $steps, string $integ): array {
    $theta = (float)($p['theta']    ?? 0);
    $omega = (float)($p['omega']    ?? 0);
    $time  = (float)($p['time']     ?? 0);
    $L     = (float)($p['length']   ?? 1);
    $g     = (float)($p['gravity']  ?? 9.81);
    $b     = (float)($p['damping']  ?? 0.1);
    $dAmp  = (float)($p['driveAmp'] ?? 0);
    $dFreq = (float)($p['driveFreq']?? 0.667);
    $dt    = (float)($p['dt']       ?? 0.016);
    $H0    = hamiltonianPendulum($theta, $omega, $L, $g);
    $states = [];
    for ($i = 0; $i < $steps; $i++) {
        switch ($integ) {
            case 'symplectic':
                [$theta, $omega] = symplecticPendulum($theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq);
                break;
            case 'euler':
                [$theta, $omega] = eulerPendulum($theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq);
                break;
            default:
                [$theta, $omega] = rk4Pendulum($theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq);
        }
        $time += $dt;
        $ke     = 0.5 * $L * $L * $omega * $omega;
        $pe     = $g * $L * (1.0 - cos($theta));
        $hdrift = ($ke + $pe) - $H0;
        $states[] = [
            round($theta,  7), round($omega,  7), round($time,   5),
            round(sin($theta) * $L, 6), round(cos($theta) * $L, 6),
            round($ke, 6), round($pe, 6), round($hdrift, 8),
        ];
    }
    return ['states' => $states, 'finalState' => ['theta' => $theta, 'omega' => $omega, 'time' => $time]];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Pendulum · Advanced Analysis</title>
<link rel="stylesheet" href="/assets/css/sim-ui.css">
<script>
window.addEventListener('message', function(e) {
  if (!e.data || e.data.type !== 'ir-theme') return;
  var t = e.data.theme;
  if (t !== 'light' && t !== 'dark') return;
  document.documentElement.setAttribute('data-theme', t);
  window.__irTheme = t;
  if (typeof window.simColors !== 'undefined') window.simColors = null;
  try { localStorage.setItem('ir_theme', t); } catch(e2) {}
});
(function() {
  var t = null;
  try { var m = window.location.search.match(/[?&]theme=(light|dark)/); if (m) t = m[1]; } catch(e) {}
  if (!t) { try { t = localStorage.getItem('ir_theme'); } catch(e) {} }
  if (!t) { var h = new Date().getHours(); t = (h >= 6 && h < 18) ? 'light' : 'dark'; }
  document.documentElement.setAttribute('data-theme', t);
  window.__irTheme = t;
})();
</script>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo defined('GA_TAG_ID') ? GA_TAG_ID : ''; ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?php echo defined('GA_TAG_ID') ? GA_TAG_ID : ''; ?>');
</script>
</head>
<body>

<nav class="tab-bar">
  <button class="tab-btn active" id="tabSimBtn" onclick="switchTab('sim')">
    <span class="tab-icon">◎</span><span>Simulation</span>
  </button>
  <button class="tab-btn" id="tabAnalBtn" onclick="switchTab('anal')">
    <span class="tab-icon">⌇</span><span>Analysis</span>
  </button>
  <button class="tab-btn" id="tabDataBtn" onclick="switchTab('data')">
    <span class="tab-icon">⊞</span><span>Data</span>
  </button>
</nav>

<div class="panel active" id="panelSim">
  <div class="canvas-zone">
    <div class="buffer-bar-wrap"><div class="buffer-bar" id="bufferBar" style="width:100%"></div></div>
    <canvas id="simCanvas"></canvas>
    <div class="eq-badge" id="eqBadge">Equilibrium</div>
    <div class="hud">
      <div class="hud-pill"><span class="lbl">θ</span><span class="val" id="hudTheta">0.000</span><span class="lbl">ω</span><span class="val" id="hudOmega">0.000</span></div>
      <div class="hud-pill"><span class="lbl">KE</span><span class="val" id="hudKE">0.000</span><span class="lbl">PE</span><span class="val" id="hudPE">0.000</span></div>
      <div class="hud-pill"><span class="lbl">t</span><span class="val" id="hudTime">0.000 s</span></div>
    </div>
  </div>
  <div class="ctrl-drawer" id="ctrlDrawer">
    <div class="ctrl-header" onclick="toggleDrawer()">
      <span class="ctrl-header-left">Parameters<span class="ctrl-eq-indicator" id="ctrlEqInd"> · Equilibrium</span></span>
      <span class="ctrl-toggle-icon" id="ctrlArrow">▲</span>
    </div>
    <div class="ctrl-body">
      <div class="integ-row">
        <button class="integ-btn active" id="iRK4"   onclick="setIntegrator('rk4')">RK4</button>
        <button class="integ-btn"        id="iSymp"  onclick="setIntegrator('symplectic')">Symplectic</button>
        <button class="integ-btn"        id="iEuler" onclick="setIntegrator('euler')">Euler</button>
      </div>
      <div class="slider-grid">
        <div class="slider-group">
          <div class="slider-label"><span class="name">Length (m)</span><span class="val" id="vLen">2.00</span></div>
          <input type="range" id="sLen" min="0.2" max="4" step="0.01" value="2" oninput="setP('length',+this.value,'vLen',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Gravity (m/s²)</span><span class="val" id="vG">9.81</span></div>
          <input type="range" id="sG" min="0.1" max="25" step="0.01" value="9.81" oninput="setP('gravity',+this.value,'vG',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Damping</span><span class="val" id="vD">0.10</span></div>
          <input type="range" id="sD" min="0" max="5" step="0.01" value="0.1" oninput="setP('damping',+this.value,'vD',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Drive Amp</span><span class="val" id="vDA">0.00</span></div>
          <input type="range" id="sDA" min="0" max="2" step="0.01" value="0" oninput="setP('driveAmp',+this.value,'vDA',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Drive Freq</span><span class="val" id="vDF">0.67</span></div>
          <input type="range" id="sDF" min="0.1" max="5" step="0.01" value="0.667" oninput="setP('driveFreq',+this.value,'vDF',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Init Angle (°)</span><span class="val" id="vA">20°</span></div>
          <input type="range" id="sA" min="-179" max="179" step="1" value="20" oninput="setInitAngle(+this.value)">
        </div>
      </div>
      <div class="action-row">
        <button class="act-btn" onclick="simDrop()">Drop</button>
        <button class="act-btn" onclick="simReset()">Reset</button>
        <button class="act-btn" onclick="applyPreset('simple')">Simple</button>
        <button class="act-btn" onclick="applyPreset('chaotic')">Chaotic</button>
        <button class="act-btn" onclick="applyPreset('moon')">Moon</button>
        <label class="trail-toggle-wrap">
          <input type="checkbox" id="trailToggle" onchange="setTrail(this.checked)">
          Trail
        </label>
      </div>
    </div>
  </div>
</div>

<div class="panel" id="panelAnal">
  <div class="analysis-grid">
    <div class="plot-cell"><div class="plot-label">Phase Portrait · θ vs ω</div><canvas id="cPhase"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Energy Drift · H(t)−H₀</div><canvas id="cEnergy"></canvas></div>
    <div class="plot-cell"><div class="plot-label">θ(t) · Angle vs Time</div><canvas id="cTheta"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Poincaré Map</div><canvas id="cPoincare"></canvas></div>
  </div>
</div>

<div class="panel" id="panelData">
  <div class="data-panel-inner">
    <div class="data-toolbar">
      <button class="data-btn" onclick="exportCSV()">↓ CSV</button>
      <button class="data-btn" onclick="exportSVG()">↓ SVG Phase</button>
      <button class="data-btn" onclick="clearDataLog()">Clear</button>
      <span class="data-eq-note" id="dataEqNote">Equilibrium</span>
      <span class="data-row-count" id="rowCount">0 rows</span>
    </div>
    <div class="data-stats">
      <div class="stat-cell"><div class="s-lbl">Max |θ|</div><div class="s-val" id="statMaxTheta">—</div></div>
      <div class="stat-cell"><div class="s-lbl">Max |ω|</div><div class="s-val" id="statMaxOmega">—</div></div>
      <div class="stat-cell"><div class="s-lbl">Max KE</div><div class="s-val" id="statMaxKE">—</div></div>
      <div class="stat-cell"><div class="s-lbl">H Drift</div><div class="s-val" id="statHDrift">—</div></div>
    </div>
    <div class="table-wrap" id="tableWrap">
      <table>
        <thead><tr><th>t (s)</th><th>θ (rad)</th><th>ω (rad/s)</th><th>KE (J)</th><th>PE (J)</th><th>H drift</th></tr></thead>
        <tbody id="dataTableBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
const API_PATH      = '?_engine=1';
const BATCH_STEPS   = 120;
const PURGE_BEHIND  = 60;
const MAX_ANAL_PTS  = 2400;
const MAX_DATA_ROWS = 1200;
const DATA_EVERY    = 6;
const EQ_WINDOW     = 90;
const EQ_OMEGA_EPS  = 0.004;
const EQ_THETA_EPS  = 0.004;
const PLOT_FPS      = 12;
const TABLE_FLUSH   = 400;

const BASE_ROD_PX = 150;
const LENGTH_REF  = 2;
const BOB_R       = 12;
const PIVOT_Y_PX  = 60;

function rodPx() { return BASE_ROD_PX * (params.length / LENGTH_REF); }

const params = {
  length: 2, gravity: 9.81, damping: 0.1,
  driveAmp: 0, driveFreq: 0.667,
  initAngle: 20 * Math.PI / 180,
  integrator: 'rk4'
};

const presets = {
  simple:   {length:1, gravity:9.81, damping:0.10, driveAmp:0,   driveFreq:0.667, initAngle:20},
  chaotic:  {length:1, gravity:9.81, damping:0.50, driveAmp:1.2, driveFreq:0.667, initAngle:20},
  resonance:{length:1, gravity:9.81, damping:0.05, driveAmp:0.4, driveFreq:3.13,  initAngle:5 },
  moon:     {length:2, gravity:1.62, damping:0.01, driveAmp:0,   driveFreq:0.5,   initAngle:90}
};

let theta = params.initAngle, omega = 0, simTime = 0, H0 = null;
let buf = [], bufHead = 0, fetching = false, draggingBob = false;
let eqConsec = 0, atEq = false;
let avgRTT = 200, rttSamples = [];
const phasePts = [], energyPts = [], thetaPts = [], poincarePts = [];
let poincarePrevT = 0;
const dataLog = [];
let pendingRows = [], tableRowCount = 0;
let statMT = 0, statMO = 0, statMK = 0, statMH = 0;
let lastPlotTs = 0, activeTab = 'sim';
const dpr = window.devicePixelRatio || 1;

let trailEnabled = false;
const trail = [];
function setTrail(on) { trailEnabled = on; if (!on) trail.length = 0; }

let simColors = null;
function buildSimColors() {
  const s = getComputedStyle(document.documentElement);
  const v = n => s.getPropertyValue(n).trim();
  simColors = {
    bobHi: v('--sim-bob-hi'), bobMid: v('--sim-bob-mid'), bobLo: v('--sim-bob-lo'),
    rod: v('--sim-rod'), pivot: v('--sim-pivot'), pivotStr: v('--sim-pivot-str'),
    trail: v('--sim-trail'), dotGrid: v('--sim-dot-grid'), axis: v('--sim-axis'),
    phaseLine: v('--sim-phase-line'), tipDot: v('--sim-tip-dot'),
    energyOk: v('--sim-energy-ok'), energyBad: v('--sim-energy-bad'),
    thetaLine: v('--sim-theta-line'), poincare: v('--sim-poincare'),
  };
}

const simCanvas = document.getElementById('simCanvas');
const ctxSim    = simCanvas.getContext('2d');

function resizeSim() {
  const z = simCanvas.parentElement;
  const w = z.clientWidth, h = z.clientHeight;
  simCanvas.width  = w * dpr; simCanvas.height = h * dpr;
  simCanvas.style.width  = w + 'px'; simCanvas.style.height = h + 'px';
  ctxSim.setTransform(dpr, 0, 0, dpr, 0, 0);
}
function resizePlotCanvas(id) {
  const c = document.getElementById(id);
  const cell = c.parentElement;
  const w = cell.clientWidth, h = cell.clientHeight;
  if (w < 2 || h < 2) return;
  c.width  = w * dpr; c.height = h * dpr;
  c.style.width  = w + 'px'; c.style.height = h + 'px';
  c.getContext('2d').setTransform(dpr, 0, 0, dpr, 0, 0);
}
function resizeAllPlots() { ['cPhase','cEnergy','cTheta','cPoincare'].forEach(resizePlotCanvas); }
window.addEventListener('resize', () => { resizeSim(); if (activeTab === 'anal') resizeAllPlots(); });
resizeSim();

function bobPos() {
  const W  = simCanvas.clientWidth;
  const cx = W / 2;
  const py = PIVOT_Y_PX;
  const r  = rodPx();
  return { bx: cx + Math.sin(theta) * r, by: py + Math.cos(theta) * r, px: cx, py };
}

function checkEq(th, om) {
  if (atEq) return;
  if (params.damping < 0.001 || params.driveAmp > 0.001) { eqConsec = 0; return; }
  if (Math.abs(om) < EQ_OMEGA_EPS && Math.abs(th) < EQ_THETA_EPS) {
    if (++eqConsec >= EQ_WINDOW) {
      atEq = true;
      document.getElementById('eqBadge').classList.add('visible');
      document.getElementById('ctrlEqInd').classList.add('visible');
      document.getElementById('dataEqNote').classList.add('visible');
    }
  } else { eqConsec = 0; }
}
function clearEq() {
  atEq = false; eqConsec = 0;
  ['eqBadge','ctrlEqInd','dataEqNote'].forEach(id => document.getElementById(id).classList.remove('visible'));
}

async function fetchBatch(fTh, fOm, fT) {
  if (fetching) return;
  fetching = true;
  const t0 = performance.now();
  try {
    const body = new URLSearchParams({
      action:'batch', type:'pendulum', integrator:params.integrator,
      steps:BATCH_STEPS, dt:0.016,
      theta:fTh, omega:fOm, time:fT,
      length:params.length, gravity:params.gravity,
      damping:params.damping, driveAmp:params.driveAmp, driveFreq:params.driveFreq
    });
    const res  = await fetch(API_PATH, {method:'POST', body});
    const json = await res.json();
    const rtt  = performance.now() - t0;
    rttSamples.push(rtt);
    if (rttSamples.length > 8) rttSamples.shift();
    avgRTT = rttSamples.reduce((a,b) => a+b, 0) / rttSamples.length;
    if (json.states && json.states.length) {
      if (bufHead > PURGE_BEHIND) { buf.splice(0, bufHead - PURGE_BEHIND); bufHead = PURGE_BEHIND; }
      buf.push(...json.states);
    }
  } catch(e) { clientFallback(fTh, fOm, fT); }
  finally { fetching = false; }
}

function clientFallback(th, om, t) {
  const dt=0.016, g=params.gravity, L=params.length, b=params.damping;
  const A=params.driveAmp, f=params.driveFreq;
  const h0 = 0.5*L*L*om*om + g*L*(1-Math.cos(th));
  const frames = [];
  for (let i = 0; i < BATCH_STEPS; i++) {
    const a = -(g/L)*Math.sin(th) - b*om + A*Math.cos(f*t);
    om += a*dt; th += om*dt; t += dt;
    const ke = 0.5*L*L*om*om, pe = g*L*(1-Math.cos(th));
    frames.push([th, om, t, 0, 0, ke, pe, (ke+pe)-h0]);
  }
  if (bufHead > PURGE_BEHIND) { buf.splice(0, bufHead - PURGE_BEHIND); bufHead = PURGE_BEHIND; }
  buf.push(...frames);
}
function prefetchThr() { return avgRTT < 80 ? 0.20 : 0.50; }

function drawSim(state) {
  if (!simColors) buildSimColors();
  const C  = simColors;
  const W  = simCanvas.clientWidth, H = simCanvas.clientHeight;
  const cx = W / 2, py = PIVOT_Y_PX;
  const r  = rodPx();
  const [th, om, t, , , ke, pe] = state;
  const bx = cx + Math.sin(th) * r;
  const by = py + Math.cos(th) * r;
  const rodW = Math.max(2, Math.min(5, r * 0.018));

  ctxSim.clearRect(0, 0, W, H);
  ctxSim.fillStyle = C.dotGrid;
  for (let x = 20; x < W; x += 40)
    for (let y = 20; y < H; y += 40) {
      ctxSim.beginPath(); ctxSim.arc(x, y, 0.8, 0, Math.PI*2); ctxSim.fill();
    }
  if (trailEnabled) {
    trail.push([bx, by]);
    if (trail.length > 320) trail.shift();
    for (let i = 1; i < trail.length; i++) {
      ctxSim.beginPath();
      ctxSim.moveTo(trail[i-1][0], trail[i-1][1]);
      ctxSim.lineTo(trail[i][0],   trail[i][1]);
      ctxSim.strokeStyle = `rgba(${C.trail},${(i / trail.length) * 0.3})`;
      ctxSim.lineWidth = 1.4; ctxSim.stroke();
    }
  }
  ctxSim.strokeStyle = C.rod; ctxSim.lineWidth = rodW; ctxSim.lineCap = 'round';
  ctxSim.beginPath(); ctxSim.moveTo(cx, py); ctxSim.lineTo(bx, by); ctxSim.stroke();
  const bg = ctxSim.createRadialGradient(bx - BOB_R*0.3, by - BOB_R*0.3, BOB_R*0.1, bx, by, BOB_R);
  bg.addColorStop(0, C.bobHi); bg.addColorStop(0.5, C.bobMid); bg.addColorStop(1, C.bobLo);
  ctxSim.beginPath(); ctxSim.arc(bx, by, BOB_R, 0, Math.PI*2);
  ctxSim.fillStyle = bg; ctxSim.fill();
  ctxSim.strokeStyle = `rgba(${C.trail},0.45)`; ctxSim.lineWidth = 1.5; ctxSim.stroke();
  ctxSim.beginPath(); ctxSim.arc(cx, py, 5, 0, Math.PI*2);
  ctxSim.fillStyle = C.pivot; ctxSim.fill();
  ctxSim.strokeStyle = C.pivotStr; ctxSim.lineWidth = 1.5; ctxSim.stroke();
  ctxSim.strokeStyle = C.pivot; ctxSim.lineWidth = 1;
  ctxSim.beginPath();
  ctxSim.moveTo(cx-8, py); ctxSim.lineTo(cx+8, py);
  ctxSim.moveTo(cx, py-8); ctxSim.lineTo(cx, py+8);
  ctxSim.stroke();
  document.getElementById('hudTheta').textContent = th.toFixed(3);
  document.getElementById('hudOmega').textContent = om.toFixed(3);
  document.getElementById('hudKE').textContent    = ke.toFixed(3);
  document.getElementById('hudPE').textContent    = pe.toFixed(3);
  document.getElementById('hudTime').textContent  = t.toFixed(2) + ' s';
}

function pad(W, H) { return Math.max(18, Math.min(40, Math.min(W,H)*0.07)); }
function axisFont(W) { return Math.max(9, Math.min(11, W*0.026)) + 'px ' + getComputedStyle(document.documentElement).getPropertyValue('--font-mono').trim(); }

function drawAxes(ctx, W, H, p, sx, sy, xl, yl) {
  if (!simColors) buildSimColors();
  const y0=sy(0), x0=sx(0);
  ctx.save();
  ctx.strokeStyle = simColors.axis; ctx.lineWidth = 1;
  if (y0>p&&y0<H-p) { ctx.beginPath(); ctx.moveTo(p,y0); ctx.lineTo(W-p,y0); ctx.stroke(); }
  if (x0>p&&x0<W-p) { ctx.beginPath(); ctx.moveTo(x0,p); ctx.lineTo(x0,H-p); ctx.stroke(); }
  ctx.fillStyle = simColors.axis; ctx.font = axisFont(W);
  ctx.fillText(xl, W-p-7, Math.max(p+4,y0-4));
  ctx.fillText(yl, Math.min(x0+3,W-p-14), p+12);
  ctx.restore();
}

function plotPhase() {
  if (!simColors) buildSimColors();
  resizePlotCanvas('cPhase');
  const c=document.getElementById('cPhase'), ctx=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  ctx.clearRect(0,0,W,H);
  if (phasePts.length<2) return;
  const p=pad(W,H);
  let mt=Infinity,xt=-Infinity,mo=Infinity,xo=-Infinity;
  for (const [t,o] of phasePts) { if(t<mt)mt=t;if(t>xt)xt=t;if(o<mo)mo=o;if(o>xo)xo=o; }
  const or=Math.max(0.05,Math.max(Math.abs(mo),Math.abs(xo)));mo=-or;xo=or;
  const sx=v=>p+(v-mt)/(xt-mt+1e-9)*(W-2*p);
  const sy=v=>H-p-(v-mo)/(xo-mo+1e-9)*(H-2*p);
  drawAxes(ctx,W,H,p,sx,sy,'θ','ω');
  const step=Math.max(1,Math.floor(phasePts.length/300));
  ctx.lineWidth=1.4;
  for (let i=step; i<phasePts.length; i+=step) {
    const a=0.12+0.88*(i/phasePts.length);
    ctx.beginPath();
    ctx.moveTo(sx(phasePts[i-step][0]),sy(phasePts[i-step][1]));
    ctx.lineTo(sx(phasePts[i][0]),sy(phasePts[i][1]));
    ctx.strokeStyle=`rgba(${simColors.phaseLine},${a})`; ctx.stroke();
  }
  const [lt,lo]=phasePts[phasePts.length-1];
  ctx.beginPath(); ctx.arc(sx(lt),sy(lo),3.5,0,Math.PI*2);
  ctx.fillStyle=simColors.tipDot; ctx.fill();
}

function plotEnergy() {
  if (!simColors) buildSimColors();
  resizePlotCanvas('cEnergy');
  const c=document.getElementById('cEnergy'), ctx=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  ctx.clearRect(0,0,W,H);
  if (energyPts.length<2) return;
  const p=pad(W,H);
  let mt=Infinity,xt=-Infinity,mh=Infinity,xh=-Infinity;
  for (const [t,h] of energyPts) { if(t<mt)mt=t;if(t>xt)xt=t;if(h<mh)mh=h;if(h>xh)xh=h; }
  const hr=Math.max(1e-8,Math.max(Math.abs(mh),Math.abs(xh)));mh=-hr;xh=hr;
  const sx=v=>p+(v-mt)/(xt-mt+1e-9)*(W-2*p);
  const sy=v=>H-p-(v-mh)/(xh-mh+1e-9)*(H-2*p);
  drawAxes(ctx,W,H,p,sx,sy,'t','ΔH');
  ctx.beginPath();
  energyPts.forEach(([t,h],i)=>{ if(i===0)ctx.moveTo(sx(t),sy(h));else ctx.lineTo(sx(t),sy(h)); });
  ctx.strokeStyle=hr>0.01?simColors.energyBad:simColors.energyOk; ctx.lineWidth=1.6; ctx.stroke();
  ctx.fillStyle=simColors.axis; ctx.font=axisFont(W);
  ctx.fillText(hr.toExponential(2),p+2,p+parseInt(axisFont(W))+3);
}

function plotTheta() {
  if (!simColors) buildSimColors();
  resizePlotCanvas('cTheta');
  const c=document.getElementById('cTheta'), ctx=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  ctx.clearRect(0,0,W,H);
  if (thetaPts.length<2) return;
  const p=pad(W,H);
  const pts=thetaPts.slice(-Math.min(600,thetaPts.length));
  const mt=pts[0][0], xt=pts[pts.length-1][0];
  let mn=Infinity, mx=-Infinity;
  for (const [,t] of pts) { if(t<mn)mn=t;if(t>mx)mx=t; }
  const tr=Math.max(0.08,Math.max(Math.abs(mn),Math.abs(mx)))*1.12;
  const sx=v=>p+(v-mt)/(xt-mt+1e-9)*(W-2*p);
  const sy=v=>H-p-(v+tr)/(2*tr)*(H-2*p);
  drawAxes(ctx,W,H,p,sx,sy,'t','θ');
  ctx.beginPath();
  pts.forEach(([t,th],i)=>{ if(i===0)ctx.moveTo(sx(t),sy(th));else ctx.lineTo(sx(t),sy(th)); });
  ctx.strokeStyle=simColors.thetaLine; ctx.lineWidth=1.6; ctx.stroke();
  ctx.fillStyle=simColors.axis; ctx.font=axisFont(W);
  ctx.fillText('±'+tr.toFixed(2)+'r',p+2,p+parseInt(axisFont(W))+3);
}

function plotPoincare() {
  if (!simColors) buildSimColors();
  resizePlotCanvas('cPoincare');
  const c=document.getElementById('cPoincare'), ctx=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  ctx.clearRect(0,0,W,H);
  if (!poincarePts.length) {
    ctx.fillStyle=simColors.axis; ctx.font=axisFont(W); ctx.textAlign='center';
    ctx.fillText('Enable drive to build map',W/2,H/2); ctx.textAlign='left'; return;
  }
  const p=pad(W,H);
  let mt=Infinity,xt=-Infinity,mo=Infinity,xo=-Infinity;
  for (const [t,o] of poincarePts) { if(t<mt)mt=t;if(t>xt)xt=t;if(o<mo)mo=o;if(o>xo)xo=o; }
  const tm=(xt-mt)*0.08, om2=(xo-mo)*0.08;
  const sx=v=>p+(v-mt+tm)/(xt-mt+2*tm+1e-9)*(W-2*p);
  const sy=v=>H-p-(v-mo+om2)/(xo-mo+2*om2+1e-9)*(H-2*p);
  drawAxes(ctx,W,H,p,sx,sy,'θ','ω');
  const r=Math.max(1.2,Math.min(2.8,W*0.004));
  ctx.fillStyle=simColors.poincare;
  for (const [t,o] of poincarePts) { ctx.beginPath();ctx.arc(sx(t),sy(o),r,0,Math.PI*2);ctx.fill(); }
  ctx.fillStyle=simColors.axis; ctx.font=axisFont(W);
  ctx.fillText(poincarePts.length+' pts',p+2,p+parseInt(axisFont(W))+3);
}

function refreshAllPlots() { plotPhase(); plotEnergy(); plotTheta(); plotPoincare(); }

function renderLoop(ts) {
  requestAnimationFrame(renderLoop);
  if (draggingBob) return;
  const avail = buf.length - bufHead, pct = avail / BATCH_STEPS;
  const bar = document.getElementById('bufferBar');
  bar.style.width = Math.min(100, pct*100) + '%';
  bar.style.background = pct<0.15 ? 'var(--color-danger)' : pct<0.45 ? 'var(--color-accent)' : 'var(--color-success)';
  if (!fetching && !atEq && avail > 0 && pct < prefetchThr()) {
    const last = buf[buf.length-1];
    if (last) fetchBatch(last[0], last[1], last[2]);
  }
  if (bufHead >= buf.length) return;
  const state = buf[bufHead++];
  const [th, om, t, , , ke, pe, hd] = state;
  theta=th; omega=om; simTime=t;
  if (H0 === null) H0 = ke+pe;
  drawSim(state);
  checkEq(th, om);
  phasePts.push([th,om]); energyPts.push([t,hd]); thetaPts.push([t,th]);
  if (phasePts.length  > MAX_ANAL_PTS) phasePts.shift();
  if (energyPts.length > MAX_ANAL_PTS) energyPts.shift();
  if (thetaPts.length  > MAX_ANAL_PTS) thetaPts.shift();
  if (params.driveAmp > 0.001 && params.driveFreq > 0) {
    const per = (2*Math.PI) / params.driveFreq;
    if (Math.floor(t/per) > Math.floor(poincarePrevT/per)) {
      poincarePts.push([th,om]); if (poincarePts.length > 4000) poincarePts.shift();
    }
    poincarePrevT = t;
  }
  if (!atEq && bufHead % DATA_EVERY === 0) {
    const row = [t,th,om,ke,pe,hd];
    dataLog.push(row); if (dataLog.length > MAX_DATA_ROWS) dataLog.shift();
    updateStats(th,om,ke,hd); pendingRows.push(row);
  }
  if (activeTab === 'anal' && ts - lastPlotTs > 1000/PLOT_FPS) {
    lastPlotTs = ts; refreshAllPlots();
  }
}

const tbody = document.getElementById('dataTableBody');
function flushRows() {
  if (activeTab !== 'data' || !pendingRows.length) return;
  const frag = document.createDocumentFragment();
  for (const row of pendingRows) {
    if (tableRowCount > 800) { tbody.deleteRow(0); tableRowCount--; }
    const tr = document.createElement('tr');
    tr.innerHTML = row.map((v,i) => `<td>${i===5?v.toExponential(2):v.toFixed(4)}</td>`).join('');
    frag.appendChild(tr); tableRowCount++;
  }
  tbody.appendChild(frag); pendingRows = [];
  document.getElementById('rowCount').textContent = dataLog.length + ' rows';
  const tw = document.getElementById('tableWrap'); tw.scrollTop = tw.scrollHeight;
}
setInterval(flushRows, TABLE_FLUSH);

function updateStats(th, om, ke, hd) {
  statMT=Math.max(statMT,Math.abs(th)); statMO=Math.max(statMO,Math.abs(om));
  statMK=Math.max(statMK,ke);          statMH=Math.max(statMH,Math.abs(hd));
  document.getElementById('statMaxTheta').textContent = statMT.toFixed(3);
  document.getElementById('statMaxOmega').textContent = statMO.toFixed(3);
  document.getElementById('statMaxKE').textContent    = statMK.toFixed(3);
  document.getElementById('statHDrift').textContent   = statMH.toExponential(2);
}

function onBob(x, y) { const b = bobPos(); return Math.hypot(x - b.bx, y - b.by) < BOB_R + 20; }
function setFromPtr(x, y) {
  const b = bobPos();
  theta = Math.atan2(x - b.px, y - b.py); omega = 0;
  buf=[]; bufHead=0; H0=null; trail.length=0; clearEq();
  phasePts.length=0; energyPts.length=0; thetaPts.length=0; poincarePts.length=0;
}
function coords(e, touch) {
  const r = simCanvas.getBoundingClientRect();
  const s = touch ? e.touches[0] : e;
  return { x: s.clientX-r.left, y: s.clientY-r.top };
}
simCanvas.addEventListener('touchstart', e=>{e.preventDefault();const{x,y}=coords(e,true);if(onBob(x,y))draggingBob=true;},{passive:false});
simCanvas.addEventListener('touchmove',  e=>{e.preventDefault();if(!draggingBob)return;const{x,y}=coords(e,true);setFromPtr(x,y);const b=bobPos();drawSim([theta,0,simTime,0,0,params.gravity*params.length*(1-Math.cos(theta)),0,0]);},{passive:false});
simCanvas.addEventListener('touchend',   e=>{e.preventDefault();if(draggingBob){draggingBob=false;fetchBatch(theta,omega,simTime);}});
simCanvas.addEventListener('mousedown',  e=>{const{x,y}=coords(e,false);if(onBob(x,y))draggingBob=true;});
simCanvas.addEventListener('mousemove',  e=>{if(!draggingBob)return;const{x,y}=coords(e,false);setFromPtr(x,y);drawSim([theta,0,simTime,0,0,params.gravity*params.length*(1-Math.cos(theta)),0,0]);});
simCanvas.addEventListener('mouseup',    ()=>{if(draggingBob){draggingBob=false;fetchBatch(theta,omega,simTime);}});
simCanvas.addEventListener('mouseleave', ()=>{draggingBob=false;});

function setP(k, v, id, fmt) { params[k]=v; document.getElementById(id).textContent=fmt(v); hardReset(); }
function setInitAngle(d) { params.initAngle=d*Math.PI/180; document.getElementById('vA').textContent=d+'°'; }
function setIntegrator(n) {
  params.integrator=n;
  [{id:'iRK4',key:'rk4'},{id:'iSymp',key:'symplectic'},{id:'iEuler',key:'euler'}].forEach(({id,key})=>{
    document.getElementById(id).classList.toggle('active',key===n);
  });
  hardReset();
}
function hardReset() {
  buf=[]; bufHead=0; H0=null; trail.length=0; clearEq();
  phasePts.length=0; energyPts.length=0; thetaPts.length=0; poincarePts.length=0;
  theta=params.initAngle; omega=0; simTime=0;
  fetchBatch(theta, omega, 0);
}
function simDrop() {
  buf=[]; bufHead=0; H0=null; trail.length=0; clearEq();
  phasePts.length=0; energyPts.length=0; thetaPts.length=0; poincarePts.length=0;
  theta=params.initAngle; omega=0; simTime=0; fetchBatch(theta,0,0);
}
function simReset() {
  params.length=2; params.gravity=9.81; params.damping=0.1;
  params.driveAmp=0; params.driveFreq=0.667; params.initAngle=20*Math.PI/180; params.integrator='rk4';
  [['sLen','vLen',2,v=>v.toFixed(2)],['sG','vG',9.81,v=>v.toFixed(2)],
   ['sD','vD',0.1,v=>v.toFixed(2)],['sDA','vDA',0,v=>v.toFixed(2)],
   ['sDF','vDF',0.667,v=>v.toFixed(2)]].forEach(([s,v,val,f])=>{
    document.getElementById(s).value=val; document.getElementById(v).textContent=f(val);
  });
  document.getElementById('sA').value=20; document.getElementById('vA').textContent='20°';
  document.getElementById('iRK4').classList.add('active');
  document.getElementById('iSymp').classList.remove('active');
  document.getElementById('iEuler').classList.remove('active');
  hardReset();
}
function applyPreset(n) {
  const P=presets[n]; if(!P) return;
  Object.assign(params,{...P, initAngle:P.initAngle*Math.PI/180});
  [['sLen','vLen','length',v=>v.toFixed(2)],['sG','vG','gravity',v=>v.toFixed(2)],
   ['sD','vD','damping',v=>v.toFixed(2)],['sDA','vDA','driveAmp',v=>v.toFixed(2)],
   ['sDF','vDF','driveFreq',v=>v.toFixed(2)]].forEach(([s,vid,k,f])=>{
    document.getElementById(s).value=P[k]; document.getElementById(vid).textContent=f(P[k]);
  });
  document.getElementById('sA').value=P.initAngle; document.getElementById('vA').textContent=P.initAngle+'°';
  hardReset();
}
function toggleDrawer() {
  const d=document.getElementById('ctrlDrawer'); d.classList.toggle('open');
  document.getElementById('ctrlArrow').textContent=d.classList.contains('open')?'▼':'▲';
}

function switchTab(n) {
  activeTab=n;
  const TM={sim:'panelSim',anal:'panelAnal',data:'panelData'};
  const BM={sim:'tabSimBtn',anal:'tabAnalBtn',data:'tabDataBtn'};
  Object.keys(TM).forEach(k=>{
    document.getElementById(TM[k]).classList.toggle('active',k===n);
    document.getElementById(BM[k]).classList.toggle('active',k===n);
  });
  if (n==='anal') requestAnimationFrame(()=>{ resizeAllPlots(); requestAnimationFrame(refreshAllPlots); });
  if (n==='data') {
    tbody.innerHTML=''; tableRowCount=0; pendingRows=[];
    const frag=document.createDocumentFragment();
    for (const row of dataLog) {
      const tr=document.createElement('tr');
      tr.innerHTML=row.map((v,i)=>`<td>${i===5?v.toExponential(2):v.toFixed(4)}</td>`).join('');
      frag.appendChild(tr); tableRowCount++;
    }
    tbody.appendChild(frag);
    document.getElementById('rowCount').textContent=dataLog.length+' rows';
    requestAnimationFrame(()=>{ document.getElementById('tableWrap').scrollTop=99999; });
  }
}

function clearDataLog() {
  dataLog.length=0; pendingRows=[]; tbody.innerHTML=''; tableRowCount=0;
  statMT=0; statMO=0; statMK=0; statMH=0;
  ['rowCount','statMaxTheta','statMaxOmega','statMaxKE','statHDrift'].forEach((id,i)=>{
    document.getElementById(id).textContent=i===0?'0 rows':'—';
  });
}
function exportCSV() {
  if (!dataLog.length) return;
  const blob=new Blob(['t,theta,omega,KE,PE,H_drift\n'+dataLog.map(r=>r.map(v=>v.toFixed(6)).join(',')).join('\n')],{type:'text/csv'});
  const a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download='pendulum_data.csv'; a.click();
}
function exportSVG() {
  if (phasePts.length<2) return;
  const W=480,H=480,p=36;
  let mt=Infinity,xt=-Infinity,mo=Infinity,xo=-Infinity;
  for (const [t,o] of phasePts) { if(t<mt)mt=t;if(t>xt)xt=t;if(o<mo)mo=o;if(o>xo)xo=o; }
  const or=Math.max(Math.abs(mo),Math.abs(xo))||1; mo=-or; xo=or;
  const sx=v=>p+(v-mt)/(xt-mt+1e-9)*(W-2*p);
  const sy=v=>H-p-(v-mo)/(xo-mo+1e-9)*(H-2*p);
  const pts=phasePts.map(([t,o])=>`${sx(t).toFixed(1)},${sy(o).toFixed(1)}`).join(' ');
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}"><rect width="${W}" height="${H}" fill="#0a0a0f"/><polyline points="${pts}" fill="none" stroke="#6c63ff" stroke-width="1.4"/></svg>`;
  const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([svg],{type:'image/svg+xml'})); a.download='pendulum_phase.svg'; a.click();
}

document.getElementById('ctrlDrawer').classList.add('open');
document.getElementById('ctrlArrow').textContent='▼';
fetchBatch(theta, omega, 0);
requestAnimationFrame(renderLoop);
</script>
</body>
</html>