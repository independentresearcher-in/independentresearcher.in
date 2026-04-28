<?php
declare(strict_types=1);
if (isset($_GET['_engine'])) {
    header('Content-Type: application/json');
    $raw  = file_get_contents('php://input');
    $post = json_decode($raw ?: '', true) ?? $_POST;
    try { echo json_encode(buoyancyBatch($post, (int)($post['steps'] ?? 120))); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
$isPaid = false;

// ── Physics ───────────────────────────────────────────────────────────────────
function buoyancyBatch(array $p, int $steps): array {
    $g         = (float)($p['gravity']      ?? 9.81);
    $rhoFluid  = (float)($p['rho_fluid']    ?? 1025.0);  // seawater kg/m3
    $rhoHull   = (float)($p['rho_hull']     ?? 7800.0);  // steel kg/m3
    $length    = (float)($p['length']       ?? 80.0);    // m
    $diameter  = (float)($p['diameter']     ?? 10.0);    // m
    $wallThick = (float)($p['wall_thick']   ?? 0.05);    // fraction of radius
    $ballast   = (float)($p['ballast_frac'] ?? 0.0);     // 0-1 ballast water fill
    $cd        = (float)($p['cd']           ?? 0.3);
    $dt        = (float)($p['dt']           ?? 0.05);
    $y         = (float)($p['y']            ?? 0.0);     // depth below surface (m, positive down)
    $vy        = (float)($p['vy']           ?? 0.0);

    $r       = $diameter / 2.0;
    $volTot  = M_PI * $r * $r * $length;
    $rInner  = $r * (1.0 - $wallThick);
    $volInner= M_PI * $rInner * $rInner * $length;
    $volHull = $volTot - $volInner;

    $mHull    = $rhoHull * $volHull;
    $mBallast = $ballast * $volInner * $rhoFluid;
    $mTotal   = $mHull + $mBallast;

    $volDisp  = $volTot;  // fully submerged
    $Fb       = $rhoFluid * $g * $volDisp;
    $Fg       = $mTotal * $g;
    $A_cross  = M_PI * $r * $r;

    $states = [];
    for ($i = 0; $i < $steps; $i++) {
        $Fd   = 0.5 * $rhoFluid * $cd * $A_cross * $vy * abs($vy);
        $Fnet = ($y < 0) ? (-$Fg) : ($Fb - $Fg - $Fd); // above surface: only gravity
        $ay   = $Fnet / $mTotal;
        $vy  += $ay * $dt;
        $y   += $vy * $dt;

        // Seabed / surface clamp
        if ($y < -200) { $y = -200; $vy = 0; }

        $depth_m = max(0.0, $y);
        $pressure_atm = 1.0 + $depth_m / 10.2;
        $pressure_pa  = $pressure_atm * 101325;

        $states[] = [
            round($y, 4),
            round($vy, 5),
            round($ay, 6),
            round($Fb, 2),
            round($Fg, 2),
            round($Fd, 4),
            round($pressure_pa, 1),
            round($pressure_atm, 3),
            round($mTotal, 2),
            round($depth_m, 4),
        ];
    }

    $sinks  = $Fg > $Fb;
    $floats = $Fb > $Fg;
    $neutral= abs($Fb - $Fg) < $Fg * 0.001;
    $status = $neutral ? 'neutral' : ($sinks ? 'sinks' : 'floats');

    return [
        'states' => $states,
        'finalY' => $y, 'finalVy' => $vy,
        'static' => [
            'Fb' => $Fb, 'Fg' => $Fg, 'mTotal' => $mTotal,
            'volDisp' => $volDisp, 'status' => $status,
            'mHull' => $mHull, 'mBallast' => $mBallast,
            'rho_eff' => $mTotal / $volTot,
        ],
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Buoyancy · Trial</title>
<link rel="stylesheet" href="/assets/css/sim-ui.css">
<style>
.status-badge {
  position: absolute; top: 10px; left: 50%; transform: translateX(-50%);
  font-family: var(--font-mono); font-size: 0.65rem; padding: 3px 12px;
  border-radius: 20px; font-weight: 700; letter-spacing: 0.08em;
  text-transform: uppercase; pointer-events: none; z-index: 10;
  backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
}
.status-badge.sinks  { background: rgba(220,53,69,0.2);  color: var(--color-danger);  border: 1px solid var(--color-danger); }
.status-badge.floats { background: rgba(23,162,184,0.2); color: var(--color-info);    border: 1px solid var(--color-info); }
.status-badge.neutral{ background: rgba(40,167,69,0.2);  color: var(--color-success); border: 1px solid var(--color-success); }
</style>
<script>
window.addEventListener('message', function(e) {
  if (!e.data || e.data.type !== 'ir-theme') return;
  var t = e.data.theme; if (t !== 'light' && t !== 'dark') return;
  document.documentElement.setAttribute('data-theme', t); window.__irTheme = t; simColors = null;
  try { localStorage.setItem('ir_theme', t); } catch(e2) {}
});
(function() {
  var t = null;
  try { var m = window.location.search.match(/[?&]theme=(light|dark)/); if (m) t = m[1]; } catch(e) {}
  if (!t) { try { t = localStorage.getItem('ir_theme'); } catch(e) {} }
  if (!t) { var h = new Date().getHours(); t = (h >= 6 && h < 18) ? 'light' : 'dark'; }
  document.documentElement.setAttribute('data-theme', t); window.__irTheme = t;
})();
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
  <button class="tab-btn locked-tab" id="tabDataBtn">
    <span class="tab-icon">⊞</span><span>Hull StressPRO</span><span class="lock-badge">PRO</span>
  </button>
</nav>

<!-- SIDE BACKDROP -->
<div class="side-backdrop" id="sideBackdrop" onclick="closePanel()"></div>

<!-- SIDE PANEL -->
<div class="side-panel" id="sidePanel">
  <div class="side-panel-header">
    <span class="side-panel-title">Submarine Parameters</span>
    <button class="side-panel-close" onclick="closePanel()" aria-label="Close">&#x2715;</button>
  </div>
  <div class="side-panel-body">
    <div class="side-section-label">Design Parameters</div>
    <div class="slider-grid">
      <div class="slider-group">
        <div class="slider-label"><span class="name">Length (m)</span><span class="val" id="vLen">80</span></div>
        <input type="range" id="sLen" min="10" max="200" step="1" value="80" oninput="setP('length',+this.value,'vLen',v=>v.toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Diameter (m)</span><span class="val" id="vDiam">10</span></div>
        <input type="range" id="sDiam" min="2" max="30" step="0.5" value="10" oninput="setP('diameter',+this.value,'vDiam',v=>v.toFixed(1))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Wall Thick (%r)</span><span class="val" id="vWall">5</span></div>
        <input type="range" id="sWall" min="1" max="30" step="1" value="5" oninput="setP('wall_thick',this.value/100,'vWall',v=>v)">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Hull Density (kg/m³)</span><span class="val" id="vRhoH">7800</span></div>
        <input type="range" id="sRhoH" min="500" max="12000" step="100" value="7800" oninput="setP('rho_hull',+this.value,'vRhoH',v=>v.toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Fluid Density (kg/m³)</span><span class="val" id="vRhoF">1025</span></div>
        <input type="range" id="sRhoF" min="800" max="1300" step="5" value="1025" oninput="setP('rho_fluid',+this.value,'vRhoF',v=>v.toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Ballast Fill %</span><span class="val" id="vBallast">0</span></div>
        <input type="range" id="sBallast" min="0" max="100" step="1" value="0" oninput="setP('ballast_frac',this.value/100,'vBallast',v=>v)">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Drag Cd</span><span class="val" id="vCd">0.30</span></div>
        <input type="range" id="sCd" min="0.1" max="1.5" step="0.05" value="0.3" oninput="setP('cd',+this.value,'vCd',v=>v.toFixed(2))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Gravity (m/s²)</span><span class="val" id="vG">9.81</span></div>
        <input type="range" id="sG" min="1" max="25" step="0.01" value="9.81" oninput="setP('gravity',+this.value,'vG',v=>v.toFixed(2))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Start Depth (m)</span><span class="val" id="vY">0</span></div>
        <input type="range" id="sY" min="-50" max="200" step="1" value="0" oninput="setInitDepth(+this.value)">
      </div>
    </div>
    <div class="side-section-label">Actions</div>
    <div class="action-row">
      <button class="act-btn" onclick="hardReset()">Reset</button>
      <button class="act-btn" onclick="applyPreset('attack')">Attack Sub</button>
      <button class="act-btn" onclick="applyPreset('boomer')">Boomer</button>
      <button class="act-btn" onclick="applyPreset('dsp')">DSP</button>
      <button class="act-btn" onclick="applyPreset('barge')">Barge</button>
    </div>
  </div>
</div>

<div class="panel active" id="panelSim">
  <div class="canvas-zone" id="canvasZone">
    <canvas id="simCanvas"></canvas>
    <div class="status-badge" id="statusBadge">—</div>
    <button class="settings-btn" id="settingsBtn" onclick="openPanel()" aria-label="Settings">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
    <div class="hud">
      <div class="hud-pill"><span class="lbl">depth</span><span class="val" id="hudDepth">0.00 m</span></div>
      <div class="hud-pill"><span class="lbl">vel</span><span class="val" id="hudVel">0.000 m/s</span></div>
      <div class="hud-pill"><span class="lbl">P</span><span class="val" id="hudP">1.0 atm</span></div>
      <div class="hud-pill"><span class="lbl">Fb/Fg</span><span class="val" id="hudRatio">—</span></div>
    </div>
  </div>
</div>
          <input type="range" id="sRhoF" min="800" max="1300" step="5" value="1025" oninput="setP('rho_fluid',+this.value,'vRhoF',v=>v.toFixed(0))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Ballast Fill %</span><span class="val" id="vBallast">0</span></div>
          <input type="range" id="sBallast" min="0" max="100" step="1" value="0" oninput="setP('ballast_frac',this.value/100,'vBallast',v=>v)">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Drag Cd</span><span class="val" id="vCd">0.30</span></div>
          <input type="range" id="sCd" min="0.1" max="1.5" step="0.05" value="0.3" oninput="setP('cd',+this.value,'vCd',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Gravity (m/s²)</span><span class="val" id="vG">9.81</span></div>
          <input type="range" id="sG" min="1" max="25" step="0.01" value="9.81" oninput="setP('gravity',+this.value,'vG',v=>v.toFixed(2))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Start Depth (m)</span><span class="val" id="vY">0</span></div>
          <input type="range" id="sY" min="-50" max="200" step="1" value="0" oninput="setInitDepth(+this.value)">
        </div>

<div class="panel" id="panelAnal">
  <div class="analysis-grid">
    <div class="plot-cell"><div class="plot-label">Depth vs Time (m)</div><canvas id="cDepth"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Velocity vs Time (m/s)</div><canvas id="cVel"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Buoyancy vs Gravity (N)</div><canvas id="cForce"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Pressure vs Depth (atm)</div><canvas id="cPres"></canvas></div>
  </div>
</div>

<div class="panel" id="panelData">
  <div class="locked-panel-inner">
    <div class="lock-overlay">
      <div class="lock-icon-big">🔧</div>
      <div class="lock-title">Hull Stress & Flow Analysis</div>
      <div class="lock-sub">Advanced structural simulation for submarine design.</div>
      <ul class="feature-list">
        <li><span class="fi">◈</span><div><span class="fname">Pressure Integrity</span><span class="fdesc">Von Mises stress at crush depth with safety factors.</span></div></li>
        <li><span class="fi">~</span><div><span class="fname">Ballast Dynamics</span><span class="fdesc">Real-time venting physics and dive/surface rates.</span></div></li>
        <li><span class="fi">⊕</span><div><span class="fname">SVG Geometry Upload</span><span class="fdesc">Flow simulation around custom hull cross-sections.</span></div></li>
        <li><span class="fi">H</span><div><span class="fname">Viscosity Heatmaps</span><span class="fdesc">Pressure and shear stress surface maps.</span></div></li>
      </ul>
      <a class="upgrade-btn" href="/upgrade" onclick="handleUpgrade(event)">Unlock Pro</a>
    </div>
  </div>
</div>

<script>
const API_PATH  = '?_engine=1';
const BATCH     = 120;
const dpr       = window.devicePixelRatio || 1;
const OCEAN_COL_DARK  = '#0a1628';
const OCEAN_COL_LIGHT = '#cce8ff';
const SURF_COL_DARK   = 'rgba(23,162,184,0.6)';
const SURF_COL_LIGHT  = 'rgba(23,162,184,0.9)';
const MAX_DEPTH = 200;  // m canvas range

const params = {
  gravity: 9.81, rho_fluid: 1025, rho_hull: 7800,
  length: 80, diameter: 10, wall_thick: 0.05,
  ballast_frac: 0, cd: 0.3,
};
let initY = 0, initVy = 0;
let curY = 0, curVy = 0;
let buf = [], bufHead = 0, fetching = false, paused = false;
let depthHist = [], velHist = [], forceHist = [], presHist = [];
let staticInfo = {};
let lastPlotTs = 0, activeTab = 'sim';
let avgRTT = 200, rttSamples = [];
let simColors = null;

const PRESETS = {
  attack: { length:98, diameter:10, wall_thick:0.08, rho_hull:7800, ballast_frac:0.15, cd:0.2 },
  boomer: { length:175, diameter:13, wall_thick:0.07, rho_hull:7800, ballast_frac:0.20, cd:0.25 },
  dsp:    { length:40,  diameter:7,  wall_thick:0.12, rho_hull:8000, ballast_frac:0.10, cd:0.35 },
  barge:  { length:120, diameter:20, wall_thick:0.03, rho_hull:3500, ballast_frac:0.40, cd:0.8  },
};

const simCanvas = document.getElementById('simCanvas');
const ctx       = simCanvas.getContext('2d');

function buildSimColors() {
  const s = getComputedStyle(document.documentElement);
  const v = n => s.getPropertyValue(n).trim();
  simColors = { bg: v('--bg-primary'), muted: v('--text-muted'), accent: v('--color-accent'),
    info: v('--color-info'), danger: v('--color-danger'), success: v('--color-success'),
    border: v('--border-color') };
}

function resizeSim() {
  const z = simCanvas.parentElement;
  const w = z.clientWidth, h = z.clientHeight;
  simCanvas.width = w*dpr; simCanvas.height = h*dpr;
  simCanvas.style.width = w+'px'; simCanvas.style.height = h+'px';
  ctx.setTransform(dpr,0,0,dpr,0,0);
}
window.addEventListener('resize', () => { resizeSim(); if (activeTab==='anal') resizePlots(); });
resizeSim();

function drawSim() {
  if (!simColors) buildSimColors();
  const W = simCanvas.clientWidth, H = simCanvas.clientHeight;
  const isDark = document.documentElement.getAttribute('data-theme') !== 'light';

  ctx.clearRect(0, 0, W, H);

  // Ocean background
  const waterGrad = ctx.createLinearGradient(0, 0, 0, H);
  waterGrad.addColorStop(0, isDark ? '#0a1628' : '#cce8ff');
  waterGrad.addColorStop(1, isDark ? '#030810' : '#5599bb');
  ctx.fillStyle = waterGrad;
  ctx.fillRect(0, 0, W, H);

  // Surface line (y=0 maps to top 15% of canvas)
  const surfaceY = H * 0.15;
  const pixPerMeter = (H * 0.8) / MAX_DEPTH;

  // Sky above surface
  ctx.fillStyle = isDark ? '#0a0a15' : '#87CEEB';
  ctx.fillRect(0, 0, W, surfaceY);

  // Surface line
  ctx.strokeStyle = isDark ? 'rgba(23,162,184,0.7)' : 'rgba(23,100,184,0.8)';
  ctx.lineWidth = 2;
  ctx.setLineDash([8, 4]);
  ctx.beginPath(); ctx.moveTo(0, surfaceY); ctx.lineTo(W, surfaceY); ctx.stroke();
  ctx.setLineDash([]);

  // Depth ruler marks
  ctx.fillStyle = isDark ? 'rgba(255,255,255,0.2)' : 'rgba(0,0,0,0.2)';
  ctx.font = `${Math.max(9, W*0.022)}px var(--font-mono, monospace)`;
  for (let d = 0; d <= MAX_DEPTH; d += 50) {
    const ty = surfaceY + d * pixPerMeter;
    if (ty > H) break;
    ctx.beginPath(); ctx.moveTo(W - 30, ty); ctx.lineTo(W - 10, ty);
    ctx.strokeStyle = 'rgba(255,255,255,0.15)'; ctx.lineWidth = 1; ctx.stroke();
    ctx.fillStyle = isDark ? 'rgba(255,255,255,0.3)' : 'rgba(0,0,0,0.3)';
    ctx.fillText(d + 'm', W - 42, ty + 4);
  }

  // Sub position (depth = max(0, curY))
  const depth  = Math.max(0, curY);
  const subY   = surfaceY + depth * pixPerMeter;
  const subLen = params.length;
  const subDiam= params.diameter;
  const scaleF = Math.min((W * 0.5) / subLen, (H * 0.15) / subDiam);
  const sw     = subLen * scaleF;
  const sh     = subDiam * scaleF;
  const sx     = (W - sw) / 2;
  const sy     = subY - sh / 2;

  // Sub body
  const subGrad = ctx.createLinearGradient(sx, sy, sx, sy + sh);
  subGrad.addColorStop(0, '#8090a0');
  subGrad.addColorStop(0.5, '#5a6878');
  subGrad.addColorStop(1, '#3a4858');
  ctx.fillStyle = subGrad;
  ctx.beginPath();
  ctx.ellipse(sx + sw/2, sy + sh/2, sw/2, sh/2, 0, 0, Math.PI*2);
  ctx.fill();
  ctx.strokeStyle = '#9aabbb'; ctx.lineWidth = 1.2; ctx.stroke();

  // Conning tower
  ctx.fillStyle = '#4a5868';
  ctx.fillRect(sx + sw*0.4, sy - sh*0.4, sw*0.12, sh*0.45);
  ctx.strokeStyle = '#7a8898'; ctx.lineWidth = 1; ctx.stroke();

  // Force arrows
  const arrowX = sx + sw + 12;
  const arrowCY = sy + sh/2;
  const Fb = staticInfo.Fb || 0, Fg = staticInfo.Fg || 0;
  const maxF = Math.max(Fb, Fg, 1);
  const maxArrow = H * 0.25;

  // Buoyancy arrow (up, teal)
  const fbLen = (Fb/maxF) * maxArrow;
  drawArrow(ctx, arrowX, arrowCY, arrowX, arrowCY - fbLen, isDark ? '#17a2b8' : '#0077aa', 'Fb');

  // Gravity arrow (down, red)
  const fgLen = (Fg/maxF) * maxArrow;
  drawArrow(ctx, arrowX + 20, arrowCY, arrowX + 20, arrowCY + fgLen, '#dc3545', 'Fg');

  // HUD update
  document.getElementById('hudDepth').textContent = depth.toFixed(2) + ' m';
  document.getElementById('hudVel').textContent   = curVy.toFixed(3) + ' m/s';
  if (staticInfo.pressure_pa !== undefined) {
    // from last state
  }
  if (Fb > 0 && Fg > 0) {
    document.getElementById('hudRatio').textContent = (Fb/Fg).toFixed(3);
  }
}

function drawArrow(ctx, x1, y1, x2, y2, color, label) {
  const len = Math.sqrt((x2-x1)**2 + (y2-y1)**2);
  if (len < 4) return;
  ctx.strokeStyle = color; ctx.lineWidth = 2;
  ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2); ctx.stroke();
  const angle = Math.atan2(y2-y1, x2-x1);
  const hw = 7;
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.moveTo(x2, y2);
  ctx.lineTo(x2 - hw*Math.cos(angle-0.4), y2 - hw*Math.sin(angle-0.4));
  ctx.lineTo(x2 - hw*Math.cos(angle+0.4), y2 - hw*Math.sin(angle+0.4));
  ctx.closePath(); ctx.fill();
  ctx.font = '9px var(--font-mono,monospace)';
  ctx.fillStyle = color;
  ctx.fillText(label, x1 + 3, y1 - 4);
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
async function fetchBatch() {
  if (fetching) return; fetching = true;
  const t0 = performance.now();
  try {
    const body = new URLSearchParams({
      action:'batch', type:'buoyancy', steps:BATCH,
      ...params, dt:0.05, y:curY, vy:curVy,
    });
    const res  = await fetch(API_PATH, { method:'POST', body });
    const json = await res.json();
    const rtt  = performance.now() - t0;
    rttSamples.push(rtt); if (rttSamples.length>8) rttSamples.shift();
    avgRTT = rttSamples.reduce((a,b)=>a+b,0)/rttSamples.length;
    if (json.states) { buf.push(...json.states); }
    if (json.static) {
      staticInfo = json.static;
      updateStatusBadge(json.static.status);
    }
    if (json.finalY !== undefined) { curY = json.finalY; curVy = json.finalVy; }
  } catch(e) { clientFallback(); }
  finally { fetching = false; }
}

function clientFallback() {
  const g=params.gravity, rhoF=params.rho_fluid, rhoH=params.rho_hull;
  const L=params.length, D=params.diameter, wt=params.wall_thick;
  const r=D/2, rInner=r*(1-wt);
  const volTot=Math.PI*r*r*L, volInner=Math.PI*rInner*rInner*L;
  const mHull=(volTot-volInner)*rhoH, mBallast=params.ballast_frac*volInner*rhoF;
  const mTotal=mHull+mBallast;
  const Fb=rhoF*g*volTot, Fg=mTotal*g, Ac=Math.PI*r*r;
  let y=curY, vy=curVy;
  for (let i=0;i<BATCH;i++) {
    const Fd=0.5*rhoF*params.cd*Ac*vy*Math.abs(vy);
    const Fnet=y<0?-Fg:(Fb-Fg-Fd);
    const ay=Fnet/mTotal;
    vy+=ay*0.05; y+=vy*0.05;
    if(y<-200){y=-200;vy=0;}
    const d=Math.max(0,y);
    buf.push([round4(y),round4(vy),round4(ay),round2(Fb),round2(Fg),round4(Fd),(1+d/10.2)*101325,(1+d/10.2).toFixed(3)*1,round2(mTotal),round4(d)]);
  }
  staticInfo={Fb,Fg,mTotal,status:Math.abs(Fb-Fg)<Fg*0.001?'neutral':(Fg>Fb?'sinks':'floats')};
  updateStatusBadge(staticInfo.status);
  curY=y; curVy=vy;
}
function round2(v){return Math.round(v*100)/100;}
function round4(v){return Math.round(v*10000)/10000;}

function updateStatusBadge(status) {
  const b = document.getElementById('statusBadge');
  b.className = 'status-badge ' + status;
  b.textContent = status === 'neutral' ? 'Neutrally Buoyant'
                : status === 'sinks'   ? 'Sinks'
                : 'Floats';
}

// ── Render loop — fixed-timestep accumulator ──────────────────────────────────
// Physics dt = 50ms. We advance exactly as many states as wall-clock time demands,
// so the submarine moves at a physically correct and visually smooth rate.
const PHYS_DT_MS = 50;
let lastRafTs = 0;
let accumMs   = 0;

function renderLoop(ts) {
  requestAnimationFrame(renderLoop);

  // Trigger fetch when buffer is getting low
  if (!fetching && buf.length - bufHead < BATCH * 0.5) fetchBatch();

  if (lastRafTs === 0) { lastRafTs = ts; return; }
  const wallDt = Math.min(ts - lastRafTs, 200); // cap at 200ms to avoid spiral on tab-hide
  lastRafTs = ts;
  accumMs += wallDt;

  let advanced = false;
  while (accumMs >= PHYS_DT_MS && bufHead < buf.length) {
    const state = buf[bufHead++];
    curY  = state[0];
    curVy = state[1];
    const depth_m = state[9];
    const pAtm    = state[7];
    document.getElementById('hudDepth').textContent = depth_m.toFixed(2) + ' m';
    document.getElementById('hudVel').textContent   = curVy.toFixed(3) + ' m/s';
    document.getElementById('hudP').textContent     = pAtm.toFixed(2) + ' atm';
    depthHist.push(depth_m); velHist.push(curVy);
    forceHist.push([state[3], state[4]]);
    presHist.push([depth_m, pAtm]);
    if (depthHist.length > 1200) { depthHist.shift(); velHist.shift(); forceHist.shift(); presHist.shift(); }
    accumMs -= PHYS_DT_MS;
    advanced = true;
  }

  if (advanced) {
    drawSim();
    if (activeTab === 'anal') refreshPlots();
  }
}

// ── Plots ─────────────────────────────────────────────────────────────────────
function resizePlot(id) {
  const c = document.getElementById(id); if (!c) return;
  const cell = c.parentElement;
  const w = cell.clientWidth, h = cell.clientHeight;
  if (w<2||h<2) return;
  c.width=w*dpr; c.height=h*dpr;
  c.style.width=w+'px'; c.style.height=h+'px';
  c.getContext('2d').setTransform(dpr,0,0,dpr,0,0);
}
function resizePlots() { ['cDepth','cVel','cForce','cPres'].forEach(resizePlot); }

function drawLine(canvasId, pts, color, xlabel, ylabel) {
  resizePlot(canvasId);
  const c = document.getElementById(canvasId); if (!c||pts.length<2) return;
  const cx2 = c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx2.clearRect(0,0,W,H);
  if (!simColors) buildSimColors();
  const p = Math.max(18, Math.min(36, Math.min(W,H)*0.07));
  let mn=pts[0], mx=pts[0];
  for (const v of pts) { if(v<mn)mn=v; if(v>mx)mx=v; }
  const range = Math.max(mn===mx?1:mx-mn, 1e-9);
  const sx = i => p + (i/(pts.length-1))*(W-2*p);
  const sy = v => H-p-(v-mn)/range*(H-2*p);
  cx2.strokeStyle='rgba(128,128,128,0.1)'; cx2.lineWidth=1;
  const y0=sy(0); if(y0>p&&y0<H-p){cx2.beginPath();cx2.moveTo(p,y0);cx2.lineTo(W-p,y0);cx2.stroke();}
  cx2.fillStyle=simColors.muted; cx2.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx2.globalAlpha=0.5; cx2.fillText(xlabel,W-p-7,H-p+12); cx2.fillText(ylabel,p+2,p+12); cx2.globalAlpha=1;
  cx2.beginPath(); cx2.moveTo(sx(0),sy(pts[0]));
  for (let i=1;i<pts.length;i++) cx2.lineTo(sx(i),sy(pts[i]));
  cx2.strokeStyle=color; cx2.lineWidth=1.6; cx2.stroke();
}

function plotForce() {
  resizePlot('cForce');
  const c = document.getElementById('cForce'); if (!c||forceHist.length<2) return;
  const cx2 = c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx2.clearRect(0,0,W,H);
  if (!simColors) buildSimColors();
  const p = Math.max(18, Math.min(36, Math.min(W,H)*0.07));
  const Fbs = forceHist.map(f=>f[0]);
  const Fgs = forceHist.map(f=>f[1]);
  const allF = [...Fbs, ...Fgs];
  let mn=allF[0], mx=allF[0];
  for (const v of allF){if(v<mn)mn=v;if(v>mx)mx=v;}
  const range=Math.max(mx-mn,1);
  const sx = i => p+(i/(forceHist.length-1))*(W-2*p);
  const sy = v => H-p-(v-mn)/range*(H-2*p);
  cx2.fillStyle=simColors.muted; cx2.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx2.globalAlpha=0.5; cx2.fillText('t',W-p-7,H-p+12); cx2.fillText('F(N)',p+2,p+12);
  cx2.globalAlpha=0.7; cx2.fillText('Fb',p+2,p+24); cx2.fillText('Fg',p+32,p+24); cx2.globalAlpha=1;
  [[Fbs,'rgba(23,162,184,0.85)'],[Fgs,'rgba(220,53,69,0.8)']].forEach(([series,col])=>{
    cx2.beginPath(); cx2.moveTo(sx(0),sy(series[0]));
    for (let i=1;i<series.length;i++) cx2.lineTo(sx(i),sy(series[i]));
    cx2.strokeStyle=col; cx2.lineWidth=1.5; cx2.stroke();
  });
}

function plotPres() {
  resizePlot('cPres');
  const c = document.getElementById('cPres'); if(!c||presHist.length<2) return;
  const cx2=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx2.clearRect(0,0,W,H);
  if (!simColors) buildSimColors();
  const p=Math.max(18,Math.min(36,Math.min(W,H)*0.07));
  let mnD=0, mxD=0, mnP=presHist[0][1], mxP=presHist[0][1];
  for (const [d,pr] of presHist){ if(d>mxD)mxD=d; if(pr<mnP)mnP=pr; if(pr>mxP)mxP=pr; }
  const rx=Math.max(mxD,1), ry=Math.max(mxP-mnP,1);
  const sx=v=>p+(v/rx)*(W-2*p);
  const sy=v=>H-p-(v-mnP)/ry*(H-2*p);
  cx2.fillStyle=simColors.muted; cx2.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx2.globalAlpha=0.5; cx2.fillText('depth(m)',W-p-40,H-p+12); cx2.fillText('P(atm)',p+2,p+12); cx2.globalAlpha=1;
  cx2.beginPath(); cx2.moveTo(sx(presHist[0][0]),sy(presHist[0][1]));
  for (const [d,pr] of presHist) cx2.lineTo(sx(d),sy(pr));
  cx2.strokeStyle='rgba(100,180,255,0.9)'; cx2.lineWidth=1.6; cx2.stroke();
}

function refreshPlots() {
  drawLine('cDepth', depthHist, 'rgba(23,162,184,0.85)', 't', 'depth(m)');
  drawLine('cVel',   velHist,   'rgba(240,192,64,0.85)', 't', 'v(m/s)');
  plotForce(); plotPres();
}

// ── Controls ──────────────────────────────────────────────────────────────────
function setP(k, v, id, fmt) {
  params[k] = +v;
  document.getElementById(id).textContent = typeof fmt === 'function' ? fmt(v) : v;
  hardReset();
}
function setInitDepth(d) { initY = d; document.getElementById('vY').textContent = d; }

function applyPreset(name) {
  const P = PRESETS[name]; if (!P) return;
  Object.assign(params, P);
  const map = {length:'sLen',diameter:'sDiam',cd:'sCd'};
  document.getElementById('sLen').value = params.length; document.getElementById('vLen').textContent = params.length;
  document.getElementById('sDiam').value = params.diameter; document.getElementById('vDiam').textContent = params.diameter.toFixed(1);
  document.getElementById('sWall').value = params.wall_thick*100; document.getElementById('vWall').textContent = params.wall_thick*100;
  document.getElementById('sRhoH').value = params.rho_hull; document.getElementById('vRhoH').textContent = params.rho_hull;
  document.getElementById('sBallast').value = params.ballast_frac*100; document.getElementById('vBallast').textContent = params.ballast_frac*100;
  document.getElementById('sCd').value = params.cd; document.getElementById('vCd').textContent = params.cd.toFixed(2);
  hardReset();
}

function hardReset() {
  curY = initY; curVy = 0;
  buf = []; bufHead = 0;
  lastRafTs = 0; accumMs = 0;
  depthHist.length = 0; velHist.length = 0; forceHist.length = 0; presHist.length = 0;
  fetchBatch();
}

function openPanel()  { document.getElementById('sidePanel').classList.add('open'); document.getElementById('sideBackdrop').classList.add('open'); }
function closePanel() { document.getElementById('sidePanel').classList.remove('open'); document.getElementById('sideBackdrop').classList.remove('open'); }

function switchTab(n) {
  activeTab = n;
  const TM = {sim:'panelSim', anal:'panelAnal', data:'panelData'};
  const BM = {sim:'tabSimBtn', anal:'tabAnalBtn', data:'tabDataBtn'};
  Object.keys(TM).forEach(k => {
    document.getElementById(TM[k]).classList.toggle('active', k===n);
    document.getElementById(BM[k]).classList.toggle('active', k===n);
  });
  const sb = document.getElementById('settingsBtn');
  if (sb) sb.style.display = n==='sim' ? '' : 'none';
  if (n !== 'sim') closePanel();
  if (n==='anal') requestAnimationFrame(()=>{ resizePlots(); requestAnimationFrame(refreshPlots); });
}

function handleUpgrade(e) {
  e.preventDefault();
  if (window.parent!==window) window.parent.postMessage({type:'upgrade_click',sim:'buoyancy'},'*');
  else window.location.href='/upgrade';
}

requestAnimationFrame(()=>{ resizeSim(); fetchBatch(); requestAnimationFrame(renderLoop); });
</script>
</body>
</html>