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
$isPaid = true;

// ── Physics ───────────────────────────────────────────────────────────────────
function buoyancyBatch(array $p, int $steps): array {
    $g          = (float)($p['gravity']      ?? 9.81);
    $rhoFluid   = (float)($p['rho_fluid']    ?? 1025.0);
    $rhoHull    = (float)($p['rho_hull']     ?? 7800.0);
    $length     = (float)($p['length']       ?? 80.0);
    $diameter   = (float)($p['diameter']     ?? 10.0);
    $wallThick  = (float)($p['wall_thick']   ?? 0.05);   // fraction of radius
    $ballast    = (float)($p['ballast_frac'] ?? 0.0);
    $cd         = (float)($p['cd']           ?? 0.3);
    $yieldMPa   = (float)($p['yield_mpa']   ?? 250.0);
    $dt         = (float)($p['dt']           ?? 0.05);
    $y          = (float)($p['y']            ?? 0.0);    // positive = below surface
    $vy         = (float)($p['vy']           ?? 0.0);

    $r       = $diameter / 2.0;
    $rInner  = $r * (1.0 - $wallThick);
    $tWall   = $r - $rInner;                             // wall thickness in metres
    $volTot  = M_PI * $r * $r * $length;
    $volInner= M_PI * $rInner * $rInner * $length;

    $mHull    = ($volTot - $volInner) * $rhoHull;
    $mBallast = $ballast * $volInner * $rhoFluid;
    $mTotal   = $mHull + $mBallast;

    $A_cross  = M_PI * $r * $r;
    $yieldPa  = $yieldMPa * 1.0e6;

    $states = [];
    for ($i = 0; $i < $steps; $i++) {
        // ── Partially-submerged volume via elliptical cap formula ─────────────
        // y < -r  : fully above surface  (only hull weight acts)
        // -r <= y <= r : partially submerged
        // y > r   : fully submerged
        if ($y <= -$r) {
            $volDisp = 0.0;
        } elseif ($y >= $r) {
            $volDisp = $volTot;
        } else {
            // submerged height h = y + r  (0 at surface-touch, 2r when fully under)
            $h = $y + $r;
            // volume of elliptical cap: V = pi * a * b * h^2 * (1 - h/(3r)) where a=b=r for circle
            $volDisp = M_PI * $r * $r * ($h * $h) * (1.0 - $h / (3.0 * $r));
            // scale from sphere formula to cylinder: multiply by length/(2r * pi/4)
            // For a cylinder laid horizontally: use 2D circular segment * length
            // Area of circular segment: A = r^2 * acos((r-h)/r) - (r-h)*sqrt(2*r*h - h*h)
            $cosArg = max(-1.0, min(1.0, ($r - $h) / $r));
            $segA = $r * $r * acos($cosArg) - ($r - $h) * sqrt(max(0.0, 2.0 * $r * $h - $h * $h));
            $volDisp = $segA * $length;
        }

        $Fb = $rhoFluid * $g * $volDisp;
        $Fg = $mTotal * $g;

        // Re-based Cd: Re = rho * v * D / mu, mu_water ~ 1e-3 Pa·s
        $speed = abs($vy);
        $Re    = ($rhoFluid * $speed * $diameter) / 1.0e-3;
        $cdEff = $cd;
        if ($Re > 1.0) {
            // Schlichting approximation blended with user Cd
            $cdSchlichting = 0.074 / pow($Re, 0.2);
            $cdEff = max($cd * 0.5, min($cd * 1.5, $cdSchlichting * 10.0 + $cd * 0.5));
        }
        $Fd = 0.5 * $rhoFluid * $cdEff * $A_cross * $vy * abs($vy);

        // Net force: above surface only gravity acts downward
        if ($y < -$r) {
            $Fnet = -$Fg;
        } else {
            $Fnet = $Fb - $Fg - $Fd;
        }
        $ay   = $Fnet / $mTotal;
        $vy  += $ay * $dt;
        $y   += $vy * $dt;

        // Seabed clamp at 2000m
        if ($y > 2000.0) { $y = 2000.0; $vy = 0.0; }
        // Sky clamp — can't fly indefinitely
        if ($y < -50.0)  { $y = -50.0;  $vy = 0.0; }

        $depth_m     = max(0.0, $y);
        $pressureAtm = 1.0 + $depth_m / 10.2;
        $pressurePa  = $pressureAtm * 101325.0;

        // Von Mises hoop stress (thin-wall cylinder): sigma = P_gauge * r_inner / t_wall
        $pGauge     = ($pressurePa - 101325.0);  // gauge pressure
        $stressPa   = ($tWall > 0) ? abs($pGauge) * $rInner / $tWall : 0.0;
        $stressMPa  = $stressPa / 1.0e6;
        $stressRatio= ($yieldPa > 0) ? $stressPa / $yieldPa : 0.0;

        $states[] = [
            round($y, 4),             // 0  position (m, + = below surface)
            round($vy, 5),            // 1  velocity (m/s)
            round($ay, 6),            // 2  acceleration (m/s²)
            round($Fb, 2),            // 3  buoyancy force (N)
            round($Fg, 2),            // 4  gravity force (N)
            round($Fd, 4),            // 5  drag force (N)
            round($pressurePa, 1),    // 6  pressure (Pa)
            round($pressureAtm, 4),   // 7  pressure (atm)
            round($mTotal, 2),        // 8  total mass (kg)
            round($depth_m, 4),       // 9  depth (m)
            round($stressMPa, 4),     // 10 hoop stress (MPa)
            round($stressRatio, 6),   // 11 stress / yield ratio
            round($volDisp, 3),       // 12 displaced volume (m³)
            round($cdEff, 5),         // 13 effective Cd
            round($Re, 1),            // 14 Reynolds number
        ];

        // Stop batch early on implosion
        if ($stressRatio >= 1.0) break;
    }

    $sinks   = $Fg > $Fb + 0.5;
    $floats  = $Fb > $Fg + 0.5;
    $neutral = !$sinks && !$floats;
    $status  = $neutral ? 'neutral' : ($sinks ? 'sinks' : 'floats');
    $imploded= isset($stressRatio) && $stressRatio >= 1.0;

    return [
        'states'   => $states,
        'finalY'   => $y,   'finalVy'  => $vy,
        'imploded' => $imploded,
        'static'   => [
            'Fb' => $Fb ?? 0, 'Fg' => $Fg ?? 0, 'mTotal' => $mTotal,
            'volDisp' => $volDisp ?? 0, 'status' => $status,
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
<title>Buoyancy & Fluid Dynamics</title>
<link rel="stylesheet" href="/assets/css/sim-ui.css">
<link rel="stylesheet" href="/assets/css/sim-buoyancy.css">
<script>
window.addEventListener('message', function(e) {
  if (!e.data || e.data.type !== 'ir-theme') return;
  var t = e.data.theme; if (t !== 'light' && t !== 'dark') return;
  document.documentElement.setAttribute('data-theme', t);
  window.__irTheme = t;
  if (typeof simColors !== 'undefined') simColors = null;
  try { localStorage.setItem('ir_theme', t); } catch(ex) {}
});
(function() {
  var t = null;
  try { var m = window.location.search.match(/[?&]theme=(light|dark)/); if (m) t = m[1]; } catch(e) {}
  if (!t) { try { t = localStorage.getItem('ir_theme'); } catch(e) {} }
  if (!t) { t = (new Date().getHours() >= 6 && new Date().getHours() < 18) ? 'light' : 'dark'; }
  document.documentElement.setAttribute('data-theme', t);
  window.__irTheme = t;
})();
</script>
</head>
<body>

<nav class="tab-bar">
  <button class="tab-btn active" id="tabSimBtn"  onclick="switchTab('sim')">
    <span class="tab-icon">◎</span><span>Simulation</span>
  </button>
  <button class="tab-btn" id="tabAnalBtn" onclick="switchTab('anal')">
    <span class="tab-icon">⌇</span><span>Analysis</span>
  </button>
  <button class="tab-btn" id="tabDataBtn" onclick="switchTab('data')">
    <span class="tab-icon">⊞</span><span>Data</span>
  </button>
</nav>

<!-- SIDE BACKDROP -->
<div class="side-backdrop" id="sideBackdrop" onclick="closePanel()"></div>

<!-- SIDE PANEL -->
<div class="side-panel" id="sidePanel">
  <div class="side-panel-header">
    <span class="side-panel-title">Design Parameters</span>
    <button class="side-panel-close" onclick="closePanel()" aria-label="Close">&#x2715;</button>
  </div>
  <div class="side-panel-body">

    <div class="side-section-label">Geometry</div>
    <div class="slider-grid">
      <div class="slider-group">
        <div class="slider-label"><span class="name">Length (m)</span><span class="val" id="vLen">80</span></div>
        <input type="range" id="sLen" min="10" max="300" step="1" value="80"
          oninput="setP('length',+this.value,'vLen',v=>v.toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Diameter (m)</span><span class="val" id="vDiam">10.0</span></div>
        <input type="range" id="sDiam" min="2" max="30" step="0.5" value="10"
          oninput="setP('diameter',+this.value,'vDiam',v=>(+v).toFixed(1))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Wall Thick (%r)</span><span class="val" id="vWall">5</span></div>
        <input type="range" id="sWall" min="1" max="30" step="1" value="5"
          oninput="setP('wall_thick',this.value/100,'vWall',v=>v)">
      </div>
    </div>

    <div class="side-section-label">Material & Integrity</div>
    <select class="material-select" id="sMaterial" onchange="applyMaterial(this.value)">
      <option value="250"  data-rho="7800">Mild Steel — 250 MPa</option>
      <option value="690"  data-rho="7850">High-Strength Steel — 690 MPa</option>
      <option value="503"  data-rho="2810">Aluminium 7075 — 503 MPa</option>
      <option value="880"  data-rho="4430">Titanium Ti-6Al-4V — 880 MPa</option>
      <option value="600"  data-rho="1600">Carbon Composite — 600 MPa</option>
    </select>
    <div class="yield-display">Yield: <span id="vYield">250</span> MPa &nbsp;|&nbsp; Hull density: <span id="vHullRho">7800</span> kg/m³</div>
    <div class="slider-group">
      <div class="slider-label"><span class="name">Yield Strength (MPa)</span><span class="val" id="vYieldSlider">250</span></div>
      <input type="range" id="sYield" min="50" max="1500" step="10" value="250"
        oninput="setYieldSlider(+this.value)">
    </div>

    <div class="side-section-label">Fluid & Physics</div>
    <div class="slider-grid">
      <div class="slider-group">
        <div class="slider-label"><span class="name">Hull Density (kg/m³)</span><span class="val" id="vRhoH">7800</span></div>
        <input type="range" id="sRhoH" min="500" max="12000" step="50" value="7800"
          oninput="setP('rho_hull',+this.value,'vRhoH',v=>(+v).toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Fluid Density (kg/m³)</span><span class="val" id="vRhoF">1025</span></div>
        <input type="range" id="sRhoF" min="800" max="1300" step="5" value="1025"
          oninput="setP('rho_fluid',+this.value,'vRhoF',v=>(+v).toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Ballast Fill %</span><span class="val" id="vBallast">0</span></div>
        <input type="range" id="sBallast" min="0" max="100" step="1" value="0"
          oninput="setP('ballast_frac',this.value/100,'vBallast',v=>v)">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Drag Cd (base)</span><span class="val" id="vCd">0.30</span></div>
        <input type="range" id="sCd" min="0.05" max="2.0" step="0.05" value="0.3"
          oninput="setP('cd',+this.value,'vCd',v=>(+v).toFixed(2))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Gravity (m/s²)</span><span class="val" id="vG">9.81</span></div>
        <input type="range" id="sG" min="1" max="25" step="0.01" value="9.81"
          oninput="setP('gravity',+this.value,'vG',v=>(+v).toFixed(2))">
      </div>
    </div>

    <div class="side-section-label">Flow Visualisation</div>
    <div class="slider-group">
      <div class="slider-label"><span class="name">Flow Rate (m/s)</span><span class="val" id="vFlow">0.0</span></div>
      <input type="range" id="sFlow" min="0" max="20" step="0.5" value="0"
        oninput="setFlowRate(+this.value)">
    </div>

    <div class="side-section-label">Neutral Depth Marker</div>
    <div class="neutral-depth-row">
      <input type="number" id="nDepthInput" min="0" max="2000" step="1" placeholder="depth m"
        oninput="setNeutralDepth(+this.value)">
      <button class="neutral-depth-clear" onclick="clearNeutralDepth()">Clear</button>
    </div>

    <div class="side-section-label">Initial Conditions</div>
    <div class="slider-group">
      <div class="slider-label"><span class="name">Start Depth (m)</span><span class="val" id="vY">0</span></div>
      <input type="range" id="sY" min="-20" max="500" step="1" value="0"
        oninput="setInitDepth(+this.value)">
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

<!-- ── SIMULATION PANEL ──────────────────────────────────────────────────── -->
<div class="panel active" id="panelSim">
  <div class="canvas-zone" id="canvasZone">
    <canvas id="simCanvas"></canvas>
    <canvas id="flowCanvas" class="flow-canvas"></canvas>

    <div class="status-badge hidden" id="statusBadge">—</div>

    <button class="settings-btn" id="settingsBtn" onclick="openPanel()" aria-label="Settings">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>

    <button class="flow-toggle-btn" id="flowToggleBtn" onclick="toggleFlow()">Flow: OFF</button>

    <div class="hud">
      <div class="hud-pill"><span class="lbl">depth</span><span class="val" id="hudDepth">0.00 m</span></div>
      <div class="hud-pill"><span class="lbl">vel</span><span class="val" id="hudVel">0.000 m/s</span></div>
      <div class="hud-pill"><span class="lbl">P</span><span class="val" id="hudP">1.00 atm</span></div>
      <div class="hud-pill"><span class="lbl">Fb/Fg</span><span class="val" id="hudRatio">—</span></div>
      <div class="hud-pill"><span class="lbl">stress</span><span class="val" id="hudStress">0.0 MPa</span></div>
    </div>

    <div class="stress-bar-wrap">
      <div class="stress-bar-label">
        <span>Hull Integrity</span>
        <span class="stress-val" id="stressBarPct">0%</span>
      </div>
      <div class="stress-bar-track"><div class="stress-bar-fill" id="stressBarFill"></div></div>
    </div>

    <div class="implosion-overlay" id="implosionOverlay">
      <div class="implosion-title">HULL FAILURE</div>
      <div class="implosion-sub" id="implosionSub">Crush depth exceeded — structural collapse</div>
      <button class="implosion-reset-btn" onclick="hardReset()">Reset Simulation</button>
    </div>
  </div>
</div>

<!-- ── ANALYSIS PANEL ────────────────────────────────────────────────────── -->
<div class="panel" id="panelAnal">
  <div class="analysis-grid">
    <div class="plot-cell"><div class="plot-label">Depth vs Time (m)</div><canvas id="cDepth"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Velocity vs Time (m/s)</div><canvas id="cVel"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Buoyancy vs Gravity (N)</div><canvas id="cForce"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Pressure vs Depth (atm)</div><canvas id="cPres"></canvas></div>
  </div>
</div>

<!-- ── DATA PANEL ────────────────────────────────────────────────────────── -->
<div class="panel" id="panelData">
  <div class="data-panel-inner">
    <div class="data-toolbar">
      <button class="csv-btn" onclick="exportCSV()">Export CSV</button>
      <span class="data-row-count" id="dataRowCount">0 / 500 rows</span>
    </div>
    <div class="table-wrap" id="tableWrap">
      <table id="dataTable">
        <thead>
          <tr>
            <th>t (s)</th>
            <th>depth (m)</th>
            <th>vel (m/s)</th>
            <th>accel (m/s²)</th>
            <th>Fb (N)</th>
            <th>Fg (N)</th>
            <th>Fd (N)</th>
            <th>P (atm)</th>
            <th>Re</th>
            <th>Cd_eff</th>
            <th>stress (MPa)</th>
            <th>stress %</th>
            <th>status</th>
          </tr>
        </thead>
        <tbody id="dataTbody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
// ── Constants ─────────────────────────────────────────────────────────────────
const API_PATH    = '?_engine=1';
const BATCH       = 120;
const PHYS_DT     = 0.05;       // seconds per physics step
const PHYS_DT_MS  = PHYS_DT * 1000;
const MAX_ROWS    = 500;
const dpr         = window.devicePixelRatio || 1;

// ── State ─────────────────────────────────────────────────────────────────────
const params = {
  gravity: 9.81, rho_fluid: 1025, rho_hull: 7800,
  length: 80, diameter: 10, wall_thick: 0.05,
  ballast_frac: 0, cd: 0.3, yield_mpa: 250,
};
const PRESETS = {
  attack: { length:98,  diameter:10, wall_thick:0.08, rho_hull:7800, ballast_frac:0.15, cd:0.20, yield_mpa:690  },
  boomer: { length:175, diameter:13, wall_thick:0.07, rho_hull:7800, ballast_frac:0.20, cd:0.25, yield_mpa:690  },
  dsp:    { length:40,  diameter:7,  wall_thick:0.12, rho_hull:8000, ballast_frac:0.10, cd:0.35, yield_mpa:880  },
  barge:  { length:120, diameter:20, wall_thick:0.03, rho_hull:3500, ballast_frac:0.40, cd:0.80, yield_mpa:250  },
};
const MATERIAL_MAP = {
  '250': { rho:7800,  label:'Mild Steel'            },
  '690': { rho:7850,  label:'High-Strength Steel'   },
  '503': { rho:2810,  label:'Aluminium 7075'        },
  '880': { rho:4430,  label:'Titanium Ti-6Al-4V'    },
  '600': { rho:1600,  label:'Carbon Composite'      },
};

let initY = 0;
// fetchCursor tracks where the next fetch should start — updated only from fetch responses
let fetchCursorY  = 0;
let fetchCursorVy = 0;
// renderCursor tracks the currently displayed position — updated only from consumed buffer states
let curY  = 0;
let curVy = 0;

let buf = [], bufHead = 0, fetching = false;
let imploded = false;
let simT = 0;  // simulation time in seconds — incremented per consumed state

let depthHist = [], velHist = [], forceHist = [], presHist = [];
let staticInfo = {};
let activeTab = 'sim';
let simColors = null;
let neutralDepth = null;
let flowRate = 0;
let flowOn = false;
let streamlines = [];   // precomputed: array of arrays of {x,y} points (normalised 0-1)
let flowParticles = []; // {sl: streamlineIndex, t: 0-1 position along streamline}
let flowNeedsRebuild = true;
let lastFlowRate = -1;

// Render loop timing
let lastRafTs = 0;
let accumMs   = 0;

// Data table rows buffer
let tableRows = [];
let tableRowTs = 0; // sim time of last appended row

// Implosion animation
let implosionTs    = 0;
let implosionState = 'none'; // 'none' | 'animating' | 'done'
let implosionDepth = 0;
let implosionFrags = [];

const simCanvas  = document.getElementById('simCanvas');
const flowCanvas = document.getElementById('flowCanvas');
const ctx        = simCanvas.getContext('2d');
const fctx       = flowCanvas.getContext('2d');

// ── Colors ────────────────────────────────────────────────────────────────────
function buildSimColors() {
  const s = getComputedStyle(document.documentElement);
  const v = n => s.getPropertyValue(n).trim();
  simColors = {
    bg: v('--bg-primary'), muted: v('--text-muted'),
    accent: v('--color-accent'), info: v('--color-info'),
    danger: v('--color-danger'), success: v('--color-success'),
    border: v('--border-color'), secondary: v('--bg-secondary'),
  };
}

// ── Canvas sizing ─────────────────────────────────────────────────────────────
function resizeSim() {
  const z = simCanvas.parentElement;
  const w = z.clientWidth, h = z.clientHeight;
  [simCanvas, flowCanvas].forEach(c => {
    c.width = w * dpr; c.height = h * dpr;
    c.style.width = w + 'px'; c.style.height = h + 'px';
  });
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  fctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  flowNeedsRebuild = true;
}
window.addEventListener('resize', () => {
  resizeSim();
  if (activeTab === 'anal') resizePlots();
});

// ── Ocean layout helpers ───────────────────────────────────────────────────────
const MAX_DEPTH_M = 2000;
function layout() {
  const W = simCanvas.clientWidth, H = simCanvas.clientHeight;
  const surfY      = H * 0.12;
  const ppm        = (H * 0.84) / MAX_DEPTH_M;  // pixels per metre
  return { W, H, surfY, ppm };
}

// ── Main draw ─────────────────────────────────────────────────────────────────
function drawSim() {
  if (!simColors) buildSimColors();
  const { W, H, surfY, ppm } = layout();
  const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
  ctx.clearRect(0, 0, W, H);

  // Sky
  ctx.fillStyle = isDark ? '#07080f' : '#c8e8f8';
  ctx.fillRect(0, 0, W, surfY);

  // Ocean gradient
  const og = ctx.createLinearGradient(0, surfY, 0, H);
  og.addColorStop(0, isDark ? '#0a1628' : '#cde8ff');
  og.addColorStop(1, isDark ? '#010408' : '#3a6688');
  ctx.fillStyle = og;
  ctx.fillRect(0, surfY, W, H - surfY);

  // Depth ruler
  const rulerX = W - 46;
  ctx.font = `${Math.max(9, W * 0.018)}px var(--font-mono, monospace)`;
  for (let d = 0; d <= MAX_DEPTH_M; d += 200) {
    const ty = surfY + d * ppm;
    if (ty > H - 4) break;
    ctx.strokeStyle = 'rgba(255,255,255,0.12)'; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(rulerX, ty); ctx.lineTo(rulerX + 14, ty); ctx.stroke();
    ctx.fillStyle = isDark ? 'rgba(255,255,255,0.28)' : 'rgba(0,0,0,0.28)';
    ctx.fillText(d + 'm', rulerX - 38, ty + 3);
  }

  // Surface line
  ctx.strokeStyle = isDark ? 'rgba(23,162,184,0.65)' : 'rgba(10,90,160,0.7)';
  ctx.lineWidth = 1.5; ctx.setLineDash([10, 5]);
  ctx.beginPath(); ctx.moveTo(0, surfY); ctx.lineTo(W, surfY); ctx.stroke();
  ctx.setLineDash([]);

  // Neutral depth marker
  if (neutralDepth !== null && neutralDepth >= 0) {
    const ndY = surfY + neutralDepth * ppm;
    if (ndY < H) {
      ctx.strokeStyle = isDark ? 'rgba(240,192,64,0.55)' : 'rgba(200,140,0,0.6)';
      ctx.lineWidth = 1; ctx.setLineDash([6, 4]);
      ctx.beginPath(); ctx.moveTo(0, ndY); ctx.lineTo(W, ndY); ctx.stroke();
      ctx.setLineDash([]);
      ctx.fillStyle = isDark ? 'rgba(240,192,64,0.75)' : 'rgba(180,120,0,0.85)';
      ctx.font = `${Math.max(8, W * 0.016)}px var(--font-mono, monospace)`;
      ctx.fillText('neutral ' + neutralDepth + 'm', 6, ndY - 3);
    }
  }

  // Sub position
  const depth    = Math.max(0, curY);
  const subCentY = surfY + depth * ppm;
  const subLen   = params.length;
  const subDiam  = params.diameter;
  const scaleF   = Math.min((W * 0.55) / subLen, (H * 0.12) / subDiam);
  const sw = subLen * scaleF, sh = subDiam * scaleF;
  const sx = (W - sw) / 2;
  const sy = subCentY - sh / 2;

  // Sub body — only draw if at or below surface
  if (curY >= -params.diameter / 2) {
    // Clip below surface if partially submerged
    ctx.save();
    if (curY < params.diameter / 2) {
      // Partially submerged — clip to ocean region
      ctx.beginPath();
      ctx.rect(0, surfY, W, H);
      ctx.clip();
    }
    const sg = ctx.createLinearGradient(sx, sy, sx, sy + sh);
    sg.addColorStop(0, '#8090a0'); sg.addColorStop(0.45, '#5a6878'); sg.addColorStop(1, '#3a4858');
    ctx.fillStyle = sg;
    ctx.beginPath(); ctx.ellipse(sx + sw/2, sy + sh/2, sw/2, sh/2, 0, 0, Math.PI*2);
    ctx.fill();
    ctx.strokeStyle = '#9aabbb'; ctx.lineWidth = 1.2; ctx.stroke();
    ctx.restore();

    // Conning tower (always above mid-hull)
    ctx.fillStyle = '#4a5868';
    ctx.fillRect(sx + sw*0.4, sy - sh*0.35, sw*0.1, sh*0.4);
    ctx.strokeStyle = '#7a8898'; ctx.lineWidth = 0.8; ctx.stroke();
  }

  // Force arrows — anchored right of sub
  const Fb = staticInfo.Fb || 0, Fg = staticInfo.Fg || 0;
  if (Fb > 0 || Fg > 0) {
    const arrowX  = sx + sw + 18;
    const arrowCY = sy + sh / 2;
    const maxF    = Math.max(Fb, Fg, 1);
    const maxLen  = Math.min(H * 0.22, 80);
    drawArrow(ctx, arrowX,      arrowCY, arrowX,      arrowCY - (Fb/maxF)*maxLen, '#17a2b8', 'Fb');
    drawArrow(ctx, arrowX + 22, arrowCY, arrowX + 22, arrowCY + (Fg/maxF)*maxLen, '#dc3545', 'Fg');
  }

  // HUD
  const stressR = (staticInfo.lastStressRatio || 0);
  document.getElementById('hudDepth').textContent  = depth.toFixed(2) + ' m';
  document.getElementById('hudVel').textContent    = curVy.toFixed(3) + ' m/s';
  document.getElementById('hudP').textContent      = (1 + depth / 10.2).toFixed(2) + ' atm';
  if (Fb > 0 && Fg > 0)
    document.getElementById('hudRatio').textContent = (Fb/Fg).toFixed(3);
  document.getElementById('hudStress').textContent = ((stressR * params.yield_mpa)).toFixed(1) + ' MPa';

  // Stress bar
  const pct = Math.min(1, stressR);
  const fill = document.getElementById('stressBarFill');
  fill.style.width = (pct * 100).toFixed(1) + '%';
  fill.className = 'stress-bar-fill' + (pct > 0.85 ? ' danger' : pct > 0.60 ? ' warn' : '');
  document.getElementById('stressBarPct').textContent = (pct * 100).toFixed(1) + '%';
}

function drawArrow(c, x1, y1, x2, y2, color, label) {
  const len = Math.hypot(x2-x1, y2-y1);
  if (len < 3) return;
  c.strokeStyle = color; c.lineWidth = 2;
  c.beginPath(); c.moveTo(x1, y1); c.lineTo(x2, y2); c.stroke();
  const angle = Math.atan2(y2-y1, x2-x1), hw = 7;
  c.fillStyle = color;
  c.beginPath();
  c.moveTo(x2, y2);
  c.lineTo(x2 - hw*Math.cos(angle-0.42), y2 - hw*Math.sin(angle-0.42));
  c.lineTo(x2 - hw*Math.cos(angle+0.42), y2 - hw*Math.sin(angle+0.42));
  c.closePath(); c.fill();
  c.font = '9px var(--font-mono,monospace)'; c.fillStyle = color;
  c.fillText(label, x1 + 4, y1 - 5);
}

// ── Flow visualisation ────────────────────────────────────────────────────────
function buildStreamlines() {
  const { W, H, surfY, ppm } = layout();
  const depth = Math.max(0, curY);
  const subCentY = surfY + depth * ppm;
  const subLen = params.length, subDiam = params.diameter;
  const scaleF = Math.min((W * 0.55) / subLen, (H * 0.12) / subDiam);
  const sw = subLen * scaleF, sh = subDiam * scaleF;
  const cx = W / 2, cy = subCentY;
  const ra = sw / 2, rb = sh / 2;

  streamlines = [];
  const N_LINES = 12;
  // Streamlines spaced vertically — flow is in the direction of motion (vertical)
  // For each lateral offset, compute a path that bends around the ellipse
  for (let li = 0; li < N_LINES; li++) {
    // x offsets from centre — symmetric both sides
    const side = li < N_LINES / 2 ? -1 : 1;
    const idx  = li % (N_LINES / 2);
    const xOff = side * (ra * 0.3 + idx * (W * 0.5 - ra * 0.5) / (N_LINES / 2 - 1));

    const pts = [];
    const nPts = 60;
    for (let pi = 0; pi < nPts; pi++) {
      // Parameterised along vertical flow direction
      const frac = pi / (nPts - 1); // 0 = top, 1 = bottom
      const yRaw = cy - H * 0.5 + frac * H;
      const x0   = cx + xOff;
      // Deflection: potential flow around cylinder approximation
      // u_x = U * (1 - (ra^2*(x^2-y^2)) / (x^2+y^2)^2)
      // Simplified: push streamline outward proportional to proximity to hull
      const dx = x0 - cx, dy = yRaw - cy;
      const dist2 = dx*dx + dy*dy;
      const rEff  = ra; // treat as circle for deflection
      let xDef = x0, yDef = yRaw;
      if (dist2 > 0) {
        const r2 = rEff * rEff;
        const deflect = r2 / dist2;
        xDef = cx + dx * (1 + deflect * 0.8);
        yDef = cy + dy * (1 + deflect * 0.3);
      }
      // Skip points inside the hull
      const insideX = (xDef - cx) / ra, insideY = (yDef - cy) / rb;
      if (insideX*insideX + insideY*insideY < 1.05) continue;
      pts.push({ x: xDef, y: yDef });
    }
    if (pts.length > 4) streamlines.push(pts);
  }

  // Rebuild particles
  const N_PARTICLES = 80;
  flowParticles = [];
  for (let i = 0; i < N_PARTICLES; i++) {
    flowParticles.push({
      sl: i % streamlines.length,
      t:  Math.random(),
    });
  }
  flowNeedsRebuild = false;
  lastFlowRate = flowRate;
}

function drawFlow(ts) {
  if (!flowOn || flowRate === 0) { fctx.clearRect(0, 0, flowCanvas.width / dpr, flowCanvas.height / dpr); return; }
  if (flowNeedsRebuild || lastFlowRate !== flowRate) buildStreamlines();
  if (!streamlines.length) return;

  const W = flowCanvas.clientWidth, H = flowCanvas.clientHeight;
  fctx.clearRect(0, 0, W, H);

  // Draw streamlines (faint)
  fctx.strokeStyle = 'rgba(23,162,184,0.15)';
  fctx.lineWidth = 0.8;
  for (const sl of streamlines) {
    if (sl.length < 2) continue;
    fctx.beginPath(); fctx.moveTo(sl[0].x, sl[0].y);
    for (let i = 1; i < sl.length; i++) fctx.lineTo(sl[i].x, sl[i].y);
    fctx.stroke();
  }

  // Advance and draw particles
  const speed = flowRate / 20; // normalised 0-1 advancement per frame
  const { surfY } = layout();

  for (const p of flowParticles) {
    const sl = streamlines[p.sl];
    if (!sl || sl.length < 2) continue;
    p.t += speed * 0.012;
    if (p.t > 1) p.t -= 1;

    const idx  = p.t * (sl.length - 1);
    const i0   = Math.floor(idx);
    const frac = idx - i0;
    const i1   = Math.min(i0 + 1, sl.length - 1);
    const px   = sl[i0].x + (sl[i1].x - sl[i0].x) * frac;
    const py   = sl[i0].y + (sl[i1].y - sl[i0].y) * frac;

    // Fade in/out near ends of streamline, also fade above surface
    const edgeFade = Math.min(p.t * 6, 1, (1 - p.t) * 6);
    const surfFade = py < surfY ? Math.max(0, (py - (surfY - 20)) / 20) : 1;
    const alpha    = 0.75 * edgeFade * surfFade;

    // Colour shifts from teal (slow) to white (fast) based on local streamline density
    fctx.beginPath();
    fctx.arc(px, py, 1.8, 0, Math.PI*2);
    fctx.fillStyle = `rgba(23,162,184,${alpha})`;
    fctx.fill();
  }
}

// ── Implosion animation ───────────────────────────────────────────────────────
function triggerImplosion(depth) {
  implosionDepth = depth;
  implosionTs = performance.now();
  implosionState = 'animating';
  // Spawn fragments
  const { W, H, surfY, ppm } = layout();
  const cy = surfY + depth * ppm;
  implosionFrags = [];
  for (let i = 0; i < 24; i++) {
    const angle = (i / 24) * Math.PI * 2;
    const speed = 2 + Math.random() * 5;
    implosionFrags.push({
      x: W / 2, y: cy, vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed - 1,
      size: 3 + Math.random() * 6, alpha: 1,
      color: Math.random() > 0.5 ? '#8090a0' : '#dc3545',
    });
  }
}

function drawImplosion(ts) {
  if (implosionState !== 'animating') return;
  const elapsed = ts - implosionTs;
  const dur = 1800; // ms
  if (elapsed > dur) {
    implosionState = 'done';
    document.getElementById('implosionOverlay').classList.add('active');
    return;
  }
  const { W, H, surfY, ppm } = layout();
  const cy = surfY + implosionDepth * ppm;
  const progress = elapsed / dur;

  // Shockwave ring
  const ringR = progress * Math.min(W, H) * 0.45;
  const ringAlpha = Math.max(0, 0.7 - progress * 0.9);
  ctx.beginPath();
  ctx.arc(W/2, cy, ringR, 0, Math.PI*2);
  ctx.strokeStyle = `rgba(220,53,69,${ringAlpha})`;
  ctx.lineWidth = 3 * (1 - progress);
  ctx.stroke();

  // Secondary ring
  if (progress > 0.1) {
    const r2 = (progress - 0.1) * Math.min(W, H) * 0.3;
    ctx.beginPath(); ctx.arc(W/2, cy, r2, 0, Math.PI*2);
    ctx.strokeStyle = `rgba(255,100,50,${ringAlpha * 0.5})`;
    ctx.lineWidth = 2; ctx.stroke();
  }

  // Fragments
  for (const f of implosionFrags) {
    f.x  += f.vx; f.y  += f.vy; f.vy += 0.15;
    f.alpha = Math.max(0, 1 - progress * 1.2);
    ctx.beginPath();
    ctx.rect(f.x - f.size/2, f.y - f.size/2, f.size, f.size);
    const col = f.color.replace(')', `,${f.alpha})`).replace('rgb', 'rgba');
    ctx.fillStyle = f.color;
    ctx.globalAlpha = f.alpha;
    ctx.fill();
    ctx.globalAlpha = 1;
  }
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
async function fetchBatch() {
  if (fetching || imploded) return;
  fetching = true;
  try {
    const body = new URLSearchParams({
      action: 'batch', type: 'buoyancy', steps: BATCH,
      ...params, dt: PHYS_DT,
      y: fetchCursorY, vy: fetchCursorVy,
    });
    const res  = await fetch(API_PATH, { method: 'POST', body });
    const json = await res.json();
    if (json.states && json.states.length) {
      buf.push(...json.states);
    }
    if (json.static) {
      staticInfo = json.static;
      updateStatusBadge(json.static.status);
    }
    // Update ONLY the fetch cursor — never curY/curVy
    if (json.finalY !== undefined) {
      fetchCursorY  = json.finalY;
      fetchCursorVy = json.finalVy;
    }
    if (json.imploded) {
      // Mark so no further fetches are queued — render loop will handle
    }
  } catch(e) { clientFallback(); }
  finally { fetching = false; }
}

function clientFallback() {
  const g=params.gravity, rhoF=params.rho_fluid, rhoH=params.rho_hull;
  const L=params.length, D=params.diameter, wt=params.wall_thick;
  const r=D/2, rInner=r*(1-wt), tWall=r-rInner;
  const volTot=Math.PI*r*r*L, volInner=Math.PI*rInner*rInner*L;
  const mHull=(volTot-volInner)*rhoH, mBallast=params.ballast_frac*volInner*rhoF;
  const mTotal=mHull+mBallast;
  const Fg=mTotal*g, Ac=Math.PI*r*r;
  const yieldPa = params.yield_mpa * 1e6;
  let y=fetchCursorY, vy=fetchCursorVy;
  for (let i=0;i<BATCH;i++) {
    const h=y+r;
    let volDisp;
    if(y<=-r) volDisp=0;
    else if(y>=r) volDisp=volTot;
    else {
      const cosArg=Math.max(-1,Math.min(1,(r-h)/r));
      const segA=r*r*Math.acos(cosArg)-(r-h)*Math.sqrt(Math.max(0,2*r*h-h*h));
      volDisp=segA*L;
    }
    const Fb=rhoF*g*volDisp;
    const speed=Math.abs(vy);
    const Re=rhoF*speed*D/1e-3;
    const cdEff=params.cd;
    const Fd=0.5*rhoF*cdEff*Ac*vy*Math.abs(vy);
    const Fnet=y<-r?-Fg:(Fb-Fg-Fd);
    const ay=Fnet/mTotal;
    vy+=ay*PHYS_DT; y+=vy*PHYS_DT;
    if(y>2000){y=2000;vy=0;} if(y<-50){y=-50;vy=0;}
    const depth=Math.max(0,y);
    const pAtm=1+depth/10.2, pPa=pAtm*101325;
    const stressPa=tWall>0?Math.abs((pPa-101325)*rInner/tWall):0;
    const stressMPa=stressPa/1e6, stressRatio=stressPa/yieldPa;
    buf.push([
      +(y.toFixed(4)),+(vy.toFixed(5)),+(ay.toFixed(6)),
      +Fb.toFixed(2),+Fg.toFixed(2),+(Fd.toFixed(4)),
      +(pPa.toFixed(1)),+(pAtm.toFixed(4)),
      +mTotal.toFixed(2),+(depth.toFixed(4)),
      +(stressMPa.toFixed(4)),+(stressRatio.toFixed(6)),
      +(volDisp.toFixed(3)),+(cdEff.toFixed(5)),+(Re.toFixed(1)),
    ]);
    if(stressRatio>=1.0)break;
  }
  staticInfo={Fb:rhoF*g*volTot,Fg,mTotal,status:Math.abs(rhoF*g*volTot-Fg)<Fg*0.001?'neutral':(Fg>rhoF*g*volTot?'sinks':'floats')};
  updateStatusBadge(staticInfo.status);
  fetchCursorY=y; fetchCursorVy=vy;
}

// ── Render loop ───────────────────────────────────────────────────────────────
function renderLoop(ts) {
  requestAnimationFrame(renderLoop);

  // Handle implosion animation — runs independently of physics
  if (implosionState === 'animating') {
    drawSim();
    drawImplosion(ts);
    if (flowOn) drawFlow(ts);
    return;
  }
  if (implosionState === 'done') return;

  // Trigger fetch when buffer is low
  if (!fetching && !imploded && buf.length - bufHead < BATCH * 0.4) fetchBatch();

  if (lastRafTs === 0) { lastRafTs = ts; return; }
  const wallDt = Math.min(ts - lastRafTs, 200);
  lastRafTs = ts;
  accumMs  += wallDt;

  let advanced = false;
  while (accumMs >= PHYS_DT_MS && bufHead < buf.length) {
    const state = buf[bufHead++];
    // Update render cursor ONLY here — never from fetch response
    curY  = state[0];
    curVy = state[1];
    simT += PHYS_DT;

    staticInfo.lastStressRatio = state[11];

    // Update history arrays
    const depth_m = state[9], pAtm = state[7];
    depthHist.push(depth_m); velHist.push(curVy);
    forceHist.push([state[3], state[4]]);
    presHist.push([depth_m, pAtm]);
    const HIST_MAX = 2400;
    if (depthHist.length > HIST_MAX) {
      depthHist.shift(); velHist.shift(); forceHist.shift(); presHist.shift();
    }

    // Data table — append every step, cap at MAX_ROWS
    appendTableRow(state, simT);

    accumMs -= PHYS_DT_MS;
    advanced = true;

    // Check implosion
    if (state[11] >= 1.0) {
      imploded = true;
      implosionSub.textContent = `Crush depth exceeded at ${depth_m.toFixed(0)} m — structural collapse`;
      triggerImplosion(depth_m);
      break;
    }
  }

  if (advanced) {
    drawSim();
    if (flowOn) drawFlow(ts);
    if (activeTab === 'anal') refreshPlots();
  } else if (flowOn) {
    // Keep flow particles animating even when physics hasn't advanced
    drawFlow(ts);
  }
}

// ── Data table ────────────────────────────────────────────────────────────────
function appendTableRow(s, t) {
  const wasFull = tableRows.length >= MAX_ROWS;
  if (wasFull) tableRows.shift();
  tableRows.push(s.concat([t]));
  // Throttle DOM rebuild: every 10 steps when not on data tab
  if (tableRows.length % 10 === 0 || activeTab === 'data') rebuildTable(wasFull);
}

let tableLastLen = 0;
function rebuildTable(wasFull) {
  if (tableRows.length === tableLastLen && !wasFull) return;
  const tb     = document.getElementById('dataTbody');
  const status = staticInfo.status || '—';

  if (wasFull) {
    // Remove the oldest rendered row — matches the tableRows.shift()
    if (tb.firstChild) tb.removeChild(tb.firstChild);
    tableLastLen = Math.max(0, tableLastLen - 1);
  }

  // Incremental: append only the new rows since last call
  const fragment = document.createDocumentFragment();
  for (let i = tableLastLen; i < tableRows.length; i++) {
    const s  = tableRows[i];
    const t  = s[15] !== undefined ? s[15] : i * PHYS_DT;
    const tr = document.createElement('tr');
    tr.innerHTML =
      `<td>${t.toFixed(2)}</td>` +
      `<td>${s[9].toFixed(2)}</td>` +
      `<td>${s[1].toFixed(4)}</td>` +
      `<td>${s[2].toFixed(5)}</td>` +
      `<td>${s[3].toFixed(0)}</td>` +
      `<td>${s[4].toFixed(0)}</td>` +
      `<td>${s[5].toFixed(2)}</td>` +
      `<td>${s[7].toFixed(3)}</td>` +
      `<td>${s[14].toFixed(0)}</td>` +
      `<td>${s[13].toFixed(4)}</td>` +
      `<td>${s[10].toFixed(3)}</td>` +
      `<td>${(s[11]*100).toFixed(2)}%</td>` +
      `<td>${status}</td>`;
    fragment.appendChild(tr);
  }
  tb.appendChild(fragment);
  tableLastLen = tableRows.length;

  document.getElementById('dataRowCount').textContent = tableRows.length + ' / ' + MAX_ROWS + ' rows';

  // Hold user scroll position — only follow if already at bottom
  const wrap = document.getElementById('tableWrap');
  const atBottom = wrap.scrollHeight - wrap.scrollTop - wrap.clientHeight < 40;
  if (atBottom) wrap.scrollTop = wrap.scrollHeight;
}

function exportCSV() {
  const headers = ['t(s)','depth(m)','vel(m/s)','accel(m/s2)','Fb(N)','Fg(N)','Fd(N)','P(atm)','Re','Cd_eff','stress(MPa)','stress%','status'];
  const status = staticInfo.status || '—';
  const rows = tableRows.map(s => {
    const t = s[15] !== undefined ? s[15] : 0;
    return [
      t.toFixed(2), s[9].toFixed(2), s[1].toFixed(4), s[2].toFixed(5),
      s[3].toFixed(0), s[4].toFixed(0), s[5].toFixed(2), s[7].toFixed(3),
      s[14].toFixed(0), s[13].toFixed(4), s[10].toFixed(3),
      (s[11]*100).toFixed(2), status,
    ].join(',');
  });
  const csv  = [headers.join(','), ...rows].join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a'); a.href = url; a.download = 'buoyancy_data.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

// ── Plots ─────────────────────────────────────────────────────────────────────
function resizePlot(id) {
  const c = document.getElementById(id); if (!c) return;
  const cell = c.parentElement;
  const w = cell.clientWidth, h = cell.clientHeight;
  if (w < 2 || h < 2) return;
  c.width = w*dpr; c.height = h*dpr;
  c.style.width = w+'px'; c.style.height = h+'px';
  c.getContext('2d').setTransform(dpr,0,0,dpr,0,0);
}
function resizePlots() { ['cDepth','cVel','cForce','cPres'].forEach(resizePlot); }

function drawLine(canvasId, pts, color, xlabel, ylabel) {
  const c = document.getElementById(canvasId); if (!c || pts.length < 2) return;
  resizePlot(canvasId);
  const cx = c.getContext('2d');
  const W = c.clientWidth, H = c.clientHeight;
  cx.clearRect(0,0,W,H);
  if (!simColors) buildSimColors();
  const p = Math.max(18, Math.min(36, Math.min(W,H)*0.07));
  let mn=pts[0], mx=pts[0];
  for (const v of pts) { if(v<mn)mn=v; if(v>mx)mx=v; }
  const range = Math.max(mn===mx ? 1 : mx-mn, 1e-9);
  const sx = i => p + (i/(pts.length-1))*(W-2*p);
  const sy = v => H-p-(v-mn)/range*(H-2*p);
  const y0=sy(0); if(y0>p&&y0<H-p){cx.strokeStyle='rgba(128,128,128,0.1)';cx.lineWidth=1;cx.beginPath();cx.moveTo(p,y0);cx.lineTo(W-p,y0);cx.stroke();}
  cx.fillStyle=simColors.muted; cx.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx.globalAlpha=0.5; cx.fillText(xlabel,W-p-7,H-p+12); cx.fillText(ylabel,p+2,p+12); cx.globalAlpha=1;
  cx.beginPath(); cx.moveTo(sx(0),sy(pts[0]));
  for (let i=1;i<pts.length;i++) cx.lineTo(sx(i),sy(pts[i]));
  cx.strokeStyle=color; cx.lineWidth=1.6; cx.stroke();
}

function plotForce() {
  const c = document.getElementById('cForce'); if (!c||forceHist.length<2) return;
  resizePlot('cForce');
  const cx = c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx.clearRect(0,0,W,H);
  if (!simColors) buildSimColors();
  const p=Math.max(18,Math.min(36,Math.min(W,H)*0.07));
  const Fbs=forceHist.map(f=>f[0]), Fgs=forceHist.map(f=>f[1]);
  const allF=[...Fbs,...Fgs]; let mn=allF[0],mx=allF[0];
  for(const v of allF){if(v<mn)mn=v;if(v>mx)mx=v;}
  const range=Math.max(mx-mn,1);
  const sx=i=>p+(i/(forceHist.length-1))*(W-2*p);
  const sy=v=>H-p-(v-mn)/range*(H-2*p);
  cx.fillStyle=simColors.muted; cx.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx.globalAlpha=0.5; cx.fillText('t',W-p-7,H-p+12); cx.fillText('F(N)',p+2,p+12);
  cx.globalAlpha=0.7; cx.fillText('Fb',p+2,p+24); cx.fillText('Fg',p+28,p+24); cx.globalAlpha=1;
  [[Fbs,'rgba(23,162,184,0.85)'],[Fgs,'rgba(220,53,69,0.8)']].forEach(([arr,col])=>{
    cx.beginPath(); cx.moveTo(sx(0),sy(arr[0]));
    for(let i=1;i<arr.length;i++) cx.lineTo(sx(i),sy(arr[i]));
    cx.strokeStyle=col; cx.lineWidth=1.5; cx.stroke();
  });
}

function plotPres() {
  const c = document.getElementById('cPres'); if(!c||presHist.length<2) return;
  resizePlot('cPres');
  const cx=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx.clearRect(0,0,W,H);
  if(!simColors)buildSimColors();
  const p=Math.max(18,Math.min(36,Math.min(W,H)*0.07));
  let mnD=0,mxD=0,mnP=presHist[0][1],mxP=presHist[0][1];
  for(const[d,pr]of presHist){if(d>mxD)mxD=d;if(pr<mnP)mnP=pr;if(pr>mxP)mxP=pr;}
  const rx=Math.max(mxD,1),ry=Math.max(mxP-mnP,1);
  const sx=v=>p+(v/rx)*(W-2*p), sy=v=>H-p-(v-mnP)/ry*(H-2*p);
  cx.fillStyle=simColors.muted; cx.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx.globalAlpha=0.5; cx.fillText('depth(m)',W-p-40,H-p+12); cx.fillText('P(atm)',p+2,p+12); cx.globalAlpha=1;
  cx.beginPath(); cx.moveTo(sx(presHist[0][0]),sy(presHist[0][1]));
  for(const[d,pr]of presHist) cx.lineTo(sx(d),sy(pr));
  cx.strokeStyle='rgba(100,180,255,0.9)'; cx.lineWidth=1.6; cx.stroke();
}

function refreshPlots() {
  drawLine('cDepth', depthHist, 'rgba(23,162,184,0.85)', 't', 'depth(m)');
  drawLine('cVel',   velHist,   'rgba(240,192,64,0.85)',  't', 'v(m/s)');
  plotForce(); plotPres();
}

// ── Status badge ──────────────────────────────────────────────────────────────
function updateStatusBadge(status) {
  const b = document.getElementById('statusBadge');
  b.className = 'status-badge ' + status;
  b.textContent = status === 'neutral' ? 'Neutrally Buoyant'
                : status === 'sinks'   ? 'Sinks'
                : 'Floats';
}

// ── Controls ──────────────────────────────────────────────────────────────────
function setP(k, v, id, fmt) {
  params[k] = +v;
  document.getElementById(id).textContent = typeof fmt === 'function' ? fmt(v) : v;
  hardReset();
}

function setInitDepth(d) {
  initY = +d;
  document.getElementById('vY').textContent = d;
}

function setYieldSlider(v) {
  params.yield_mpa = v;
  document.getElementById('vYieldSlider').textContent = v;
  document.getElementById('vYield').textContent = v;
  hardReset();
}

function applyMaterial(yieldStr) {
  const mat = MATERIAL_MAP[yieldStr];
  if (!mat) return;
  params.yield_mpa = +yieldStr;
  params.rho_hull  = mat.rho;
  document.getElementById('vYield').textContent      = yieldStr;
  document.getElementById('vYieldSlider').textContent = yieldStr;
  document.getElementById('sYield').value            = yieldStr;
  document.getElementById('vHullRho').textContent    = mat.rho;
  document.getElementById('sRhoH').value             = mat.rho;
  document.getElementById('vRhoH').textContent       = mat.rho;
  hardReset();
}

function setFlowRate(v) {
  flowRate = v;
  document.getElementById('vFlow').textContent = v.toFixed(1);
  flowNeedsRebuild = true;
  if (v > 0 && !flowOn) { flowOn = true; document.getElementById('flowCanvas').classList.add('visible'); document.getElementById('flowToggleBtn').classList.add('active'); document.getElementById('flowToggleBtn').textContent = 'Flow: ON'; }
  if (v === 0 && flowOn) { flowOn = false; document.getElementById('flowCanvas').classList.remove('visible'); document.getElementById('flowToggleBtn').classList.remove('active'); document.getElementById('flowToggleBtn').textContent = 'Flow: OFF'; }
}

function toggleFlow() {
  flowOn = !flowOn;
  document.getElementById('flowCanvas').classList.toggle('visible', flowOn);
  document.getElementById('flowToggleBtn').classList.toggle('active', flowOn);
  document.getElementById('flowToggleBtn').textContent = 'Flow: ' + (flowOn ? 'ON' : 'OFF');
  if (flowOn) flowNeedsRebuild = true;
}

function setNeutralDepth(v) {
  neutralDepth = isNaN(v) || v < 0 ? null : v;
}
function clearNeutralDepth() {
  neutralDepth = null;
  document.getElementById('nDepthInput').value = '';
}

function applyPreset(name) {
  const P = PRESETS[name]; if (!P) return;
  Object.assign(params, P);
  document.getElementById('sLen').value      = P.length;       document.getElementById('vLen').textContent      = P.length;
  document.getElementById('sDiam').value     = P.diameter;     document.getElementById('vDiam').textContent     = P.diameter.toFixed(1);
  document.getElementById('sWall').value     = P.wall_thick*100; document.getElementById('vWall').textContent   = P.wall_thick*100;
  document.getElementById('sRhoH').value     = P.rho_hull;     document.getElementById('vRhoH').textContent     = P.rho_hull;
  document.getElementById('sBallast').value  = P.ballast_frac*100; document.getElementById('vBallast').textContent = P.ballast_frac*100;
  document.getElementById('sCd').value       = P.cd;           document.getElementById('vCd').textContent       = P.cd.toFixed(2);
  document.getElementById('sYield').value    = P.yield_mpa;    document.getElementById('vYieldSlider').textContent = P.yield_mpa;
  document.getElementById('vYield').textContent = P.yield_mpa;
  hardReset();
}

function hardReset() {
  imploded = false;
  implosionState = 'none';
  document.getElementById('implosionOverlay').classList.remove('active');
  curY = initY; curVy = 0;
  fetchCursorY = initY; fetchCursorVy = 0;
  buf = []; bufHead = 0;
  lastRafTs = 0; accumMs = 0;
  simT = 0;
  depthHist.length = 0; velHist.length = 0; forceHist.length = 0; presHist.length = 0;
  tableRows.length = 0; tableLastLen = 0;
  document.getElementById('dataTbody').innerHTML = '';
  document.getElementById('dataRowCount').textContent = '0 / ' + MAX_ROWS + ' rows';
  document.getElementById('statusBadge').className = 'status-badge hidden';
  staticInfo = {};
  flowNeedsRebuild = true;
  fetchBatch();
}

function openPanel()  { document.getElementById('sidePanel').classList.add('open'); document.getElementById('sideBackdrop').classList.add('open'); }
function closePanel() { document.getElementById('sidePanel').classList.remove('open'); document.getElementById('sideBackdrop').classList.remove('open'); }

function switchTab(n) {
  activeTab = n;
  const TM = { sim:'panelSim', anal:'panelAnal', data:'panelData' };
  const BM = { sim:'tabSimBtn', anal:'tabAnalBtn', data:'tabDataBtn' };
  Object.keys(TM).forEach(k => {
    document.getElementById(TM[k]).classList.toggle('active', k===n);
    document.getElementById(BM[k]).classList.toggle('active', k===n);
  });
  const sb = document.getElementById('settingsBtn');
  if (sb) sb.style.display = n === 'sim' ? '' : 'none';
  if (n !== 'sim') closePanel();
  if (n === 'anal') requestAnimationFrame(() => { resizePlots(); requestAnimationFrame(refreshPlots); });
  if (n === 'data') rebuildTable(false);
}

// ── Boot ──────────────────────────────────────────────────────────────────────
const implosionSub = document.getElementById('implosionSub');
requestAnimationFrame(() => { resizeSim(); fetchBatch(); requestAnimationFrame(renderLoop); });
</script>
</body>
</html>