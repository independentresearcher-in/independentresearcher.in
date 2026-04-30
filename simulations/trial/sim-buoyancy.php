<?php
declare(strict_types=1);

if (isset($_GET['_engine'])) {
    ob_start();
    header('Content-Type: application/json');
    $raw  = file_get_contents('php://input');
    $post = json_decode($raw ?: '', true);
    if (!is_array($post)) $post = $_POST;
    try {
        $stepCount = isset($post['steps']) ? (int)$post['steps'] : 120;
        ob_end_clean();
        echo json_encode(runBuoyancyBatch($post, $stepCount));
    } catch (Throwable $e) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'error'   => $e->getMessage(),
            'file'    => basename($e->getFile()),
            'line'    => $e->getLine(),
        ]);
    }
    exit;
}
$isPaid = false;

// ── Geometry helpers ──────────────────────────────────────────────────────────

/**
 * Displaced volume of a horizontal cylinder whose centre is at $centreDepth.
 * Uses the circular-segment formula for accurate partial submersion at the surface.
 */
function submergedVolume(
    float $centreDepth,
    float $outerRadius,
    float $hullLength
): float {
    if ($centreDepth <= -$outerRadius) return 0.0;
    if ($centreDepth >=  $outerRadius) return M_PI * pow($outerRadius, 2) * $hullLength;

    // h = submerged height, from 0 (hull just touches surface) to 2r (fully under)
    $submergedHeight = $centreDepth + $outerRadius;
    $cosArg          = max(-1.0, min(1.0, ($outerRadius - $submergedHeight) / $outerRadius));
    $segmentArea     = pow($outerRadius, 2) * acos($cosArg)
                     - ($outerRadius - $submergedHeight)
                     * sqrt(max(0.0,
                         2.0 * $outerRadius * $submergedHeight - pow($submergedHeight, 2)
                     ));
    return $segmentArea * $hullLength;
}

/**
 * Von Mises hoop stress (thin-wall) for external pressure loading.
 * sigma = P_gauge * r_inner / t_wall
 * Returns 0 if wall thickness is zero to avoid division errors.
 */
function hoopStressPa(
    float $gaugePressurePa,
    float $innerRadius,
    float $wallThickness
): float {
    return ($wallThickness > 0)
        ? abs($gaugePressurePa) * $innerRadius / $wallThickness
        : 0.0;
}

/**
 * Effective drag coefficient blended with a Schlichting turbulent-BL estimate.
 * Kept close to the user-supplied baseline — Re correction is intentionally modest.
 */
function effectiveDragCoefficient(float $baseCd, float $reynoldsNumber): float {
    if ($reynoldsNumber <= 1.0) return $baseCd;
    $cdSchlichting = 0.074 / (pow($reynoldsNumber, 0.2));
    return max($baseCd * 0.5, min($baseCd * 1.5, $cdSchlichting * 10.0 + $baseCd * 0.5));
}

// ── Physics batch ─────────────────────────────────────────────────────────────

/**
 * Integrate $stepCount Euler steps of buoyancy physics.
 * Position and velocity are seeded from $params, allowing the caller to
 * continue a previous batch seamlessly.
 */
function runBuoyancyBatch(array $params, int $stepCount): array {

    // --- Input parameters ---
    $gravity         = (float)($params['gravity']      ?? 9.81);
    $fluidDensity    = (float)($params['rho_fluid']    ?? 1025.0);
    $hullDensity     = (float)($params['rho_hull']     ?? 7800.0);
    $hullLength      = (float)($params['length']       ?? 80.0);
    $outerDiameter   = (float)($params['diameter']     ?? 10.0);
    // wall_thick = t_wall / r_outer (fraction, not absolute metres)
    $wallFraction    = (float)($params['wall_thick']   ?? 0.05);
    $ballastFraction = (float)($params['ballast_frac'] ?? 0.0);
    $baseDragCd      = (float)($params['cd']           ?? 0.3);
    $yieldMPa        = (float)($params['yield_mpa']    ?? 250.0);
    $timeStep        = (float)($params['dt']           ?? 0.05);
    $centreDepth     = (float)($params['y']            ?? 0.0);  // +ve = below surface
    $velocity        = (float)($params['vy']           ?? 0.0);  // +ve = sinking

    // --- Derived geometry ---
    $outerRadius    = $outerDiameter / 2.0;
    $innerRadius    = $outerRadius * (1.0 - $wallFraction);
    $wallThickness  = $outerRadius - $innerRadius;
    $totalVolume    = M_PI * pow($outerRadius, 2) * $hullLength;
    $innerVolume    = M_PI * pow($innerRadius, 2) * $hullLength;
    $frontalArea    = M_PI * pow($outerRadius, 2);

    // --- Mass ---
    $hullMass    = ($totalVolume - $innerVolume) * $hullDensity;
    $ballastMass = $ballastFraction * $innerVolume * $fluidDensity;
    $totalMass   = $hullMass + $ballastMass;

    $yieldPa      = $yieldMPa * 1.0e6;
    $mu_water     = 1.0e-3;        // Pa·s, seawater viscosity ~20°C
    $patm         = 101325.0;      // Pa

    $states      = [];
    $stressRatio = 0.0;

    for ($step = 0; $step < $stepCount; $step++) {

        // Forces
        $displacedVolume = submergedVolume($centreDepth, $outerRadius, $hullLength);
        $buoyancyForce   = $fluidDensity * $gravity * $displacedVolume;
        $weightForce     = $totalMass * $gravity;

        $speed          = abs($velocity);
        $reynoldsNumber = $fluidDensity * $speed * $outerDiameter / $mu_water;
        $dragCd         = effectiveDragCoefficient($baseDragCd, $reynoldsNumber);
        // Drag force opposes motion: sign follows velocity
        $dragForce      = 0.5 * $fluidDensity * $dragCd * $frontalArea * $velocity * $speed;

        // Net upward force. Above surface: only weight acts (no buoyancy, no drag).
        $aboveSurface = $centreDepth < -$outerRadius;
        $netForce     = $aboveSurface
                      ? -$weightForce
                      : $buoyancyForce - $weightForce - $dragForce;

        // Euler integration
        $acceleration = $netForce / $totalMass;
        $velocity    += $acceleration * $timeStep;
        $centreDepth += $velocity    * $timeStep;

        // Clamps
        if ($centreDepth < -$outerRadius) {
            // Hull cannot break the surface upward
            $centreDepth = -$outerRadius;
            if ($velocity < 0) $velocity = 0.0;
        }
        if ($centreDepth > 11000.0) {
            // Challenger Deep is the physical floor
            $centreDepth = 11000.0;
            $velocity    = 0.0;
        }

        // Pressure and stress
        $depthBelow  = max(0.0, $centreDepth);
        $pressureAtm = 1.0 + $depthBelow / 10.2;   // 1 atm per 10.2 m seawater
        $pressurePa  = $pressureAtm * $patm;
        $gaugePa     = $pressurePa - $patm;

        $stressPa    = hoopStressPa($gaugePa, $innerRadius, $wallThickness);
        $stressMPa   = $stressPa / 1.0e6;
        $stressRatio = ($yieldPa > 0) ? $stressPa / $yieldPa : 0.0;

        // State vector — index order must stay stable (consumed by JS by index)
        $states[] = [
            round($centreDepth,  4),   // 0  centre depth (m, +ve = below surface)
            round($velocity,     5),   // 1  vertical velocity (m/s, +ve = sinking)
            round($acceleration, 6),   // 2  acceleration (m/s²)
            round($buoyancyForce,2),   // 3  buoyancy force Fb (N)
            round($weightForce,  2),   // 4  weight force Fg (N)
            round($dragForce,    4),   // 5  drag force Fd (N)
            round($pressurePa,   1),   // 6  absolute pressure (Pa)
            round($pressureAtm,  4),   // 7  pressure (atm)
            round($totalMass,    2),   // 8  total mass (kg)
            round($depthBelow,   4),   // 9  depth below surface (m)
            round($stressMPa,    4),   // 10 hoop stress (MPa)
            round($stressRatio,  6),   // 11 stress / yield ratio (1.0 = failure)
            round($displacedVolume,3), // 12 displaced volume (m³)
            round($dragCd,       5),   // 13 effective Cd
            round($reynoldsNumber,1),  // 14 Reynolds number
        ];

        if ($stressRatio >= 1.0) break; // hull failed — no point continuing
    }

    // Long-run tendency: compare weight vs max possible buoyancy (fully submerged).
    // Drag and partial submersion are transient — this comparison is what matters.
    $maxBuoyancy = $fluidDensity * $gravity * $totalVolume;
    if ($weightForce > $maxBuoyancy * 1.001) {
        $status = 'sinks';
    } elseif ($maxBuoyancy > $weightForce * 1.001) {
        $status = 'floats';
    } else {
        $status = 'neutral';
    }

    // Ballast fraction for neutral buoyancy:
    // hullMass + b * innerVolume * fluidDensity = fluidDensity * totalVolume
    $neutralBallast = ($innerVolume > 0)
        ? max(0.0, min(1.0,
            ($fluidDensity * $totalVolume - $hullMass) / ($innerVolume * $fluidDensity)
          ))
        : null;

    return [
        'states'   => $states,
        'finalY'   => $centreDepth,
        'finalVy'  => $velocity,
        'imploded' => $stressRatio >= 1.0,
        'static'   => [
            'Fb'             => $maxBuoyancy,
            'Fg'             => $weightForce,
            'mTotal'         => $totalMass,
            'volDisp'        => $totalVolume,
            'status'         => $status,
            'mHull'          => $hullMass,
            'mBallast'       => $ballastMass,
            'rho_eff'        => $totalMass / $totalVolume,
            'ballastNeutral' => $neutralBallast,
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
<style>
/* Trial: locked plot cells need relative positioning for the overlay */
.locked-plot-cell { position: relative; overflow: hidden; }
/* Analysis tab remains visible so user can see the one free graph */
#panelAnal { display: none; }
#panelAnal.active { display: flex; }
</style>
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
  <button class="tab-btn active" id="tabSimBtn" onclick="switchTab('sim')">
    <span class="tab-icon">◎</span><span>Simulation</span>
  </button>
  <button class="tab-btn" id="tabAnalBtn" onclick="switchTab('anal')">
    <span class="tab-icon">⌇</span><span>Analysis</span>
  </button>
  <button class="tab-btn locked-tab" id="tabDataBtn" onclick="handleUpgrade(event)">
    <span class="tab-icon">⊞</span><span>Data</span><span class="lock-badge">PRO</span>
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

    <div class="trial-badge">Free Trial — Limited Controls</div>

    <div class="side-section-label">Geometry</div>
    <div class="slider-grid">
      <div class="slider-group">
        <div class="slider-label"><span class="name">Length (m)</span><span class="val" id="vLen">80</span></div>
        <input type="range" id="sLen" min="10" max="300" step="1" value="80"
          oninput="setSimParam('length',+this.value,'vLen',v=>v.toFixed(0))">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Diameter (m)</span><span class="val" id="vDiam">10.0</span></div>
        <input type="range" id="sDiam" min="2" max="30" step="0.5" value="10"
          oninput="setSimParam('diameter',+this.value,'vDiam',v=>(+v).toFixed(1))">
      </div>
    </div>

    <!-- Locked: Wall Thickness, Material, Fluid, Flow, Depth, Presets -->
    <div class="locked-panel-inner" style="min-height:420px;margin-top:8px">
      <div class="ghost-preview">
        <div class="side-section-label">Material &amp; Integrity</div>
        <div class="slider-group" style="margin-bottom:10px">
          <div class="slider-label"><span class="name">Wall Thick (%r)</span><span class="val">5.00</span></div>
          <input type="range" value="50" disabled style="width:100%">
        </div>
        <div class="slider-group" style="margin-bottom:10px">
          <div class="slider-label"><span class="name">Yield Strength (MPa)</span><span class="val">250</span></div>
          <input type="range" value="20" disabled style="width:100%">
        </div>
        <div class="side-section-label">Fluid &amp; Physics</div>
        <div class="slider-group" style="margin-bottom:10px">
          <div class="slider-label"><span class="name">Hull Density (kg/m³)</span><span class="val">7800</span></div>
          <input type="range" value="60" disabled style="width:100%">
        </div>
        <div class="slider-group" style="margin-bottom:10px">
          <div class="slider-label"><span class="name">Ballast Fill %</span><span class="val">0</span></div>
          <input type="range" value="0" disabled style="width:100%">
        </div>
        <div class="slider-group" style="margin-bottom:10px">
          <div class="slider-label"><span class="name">Drag Cd</span><span class="val">0.30</span></div>
          <input type="range" value="15" disabled style="width:100%">
        </div>
        <div class="side-section-label">Actions</div>
        <div class="action-row" style="flex-wrap:wrap;gap:6px">
          <button class="act-btn" disabled>Attack Sub</button>
          <button class="act-btn" disabled>Boomer</button>
          <button class="act-btn" disabled>Thresher</button>
          <button class="act-btn" disabled>Titan</button>
        </div>
      </div>
      <div class="lock-overlay">
        <div class="lock-icon-big">🔒</div>
        <div class="lock-title">Advanced Controls</div>
        <div class="lock-sub">Unlock material selection, ballast, drag, hull integrity, presets and flow visualisation.</div>
        <ul class="feature-list">
          <li><span class="fi">⚙</span><span><span class="fname">Material &amp; Hull Integrity</span><span class="fdesc">Steel, titanium, carbon composite with crush-depth calculation</span></span></li>
          <li><span class="fi">🌊</span><span><span class="fname">Ballast &amp; Drag Control</span><span class="fdesc">Neutral buoyancy tuning and Re-based drag model</span></span></li>
          <li><span class="fi">〰</span><span><span class="fname">Flow Visualisation</span><span class="fdesc">Hydrodynamic streamlines around the hull</span></span></li>
          <li><span class="fi">🚢</span><span><span class="fname">Vessel Presets</span><span class="fdesc">Thresher, Titan, Attack sub, Boomer and more</span></span></li>
        </ul>
        <a href="/upgrade" class="upgrade-btn" onclick="handleUpgrade(event)">⚡ Upgrade to PRO</a>
      </div>
    </div>

    <div class="side-section-label" style="margin-top:12px">Actions</div>
    <div class="action-row">
      <button class="act-btn" onclick="hardReset()">Reset</button>
    </div>

  </div>
</div>

<!-- ── SIMULATION PANEL ──────────────────────────────────────────────────── -->
<div class="panel active" id="panelSim">
  <div class="canvas-zone" id="canvasZone">
    <canvas id="simCanvas"></canvas>

    <div class="status-badge hidden" id="statusBadge">—</div>

    <button class="settings-btn" id="settingsBtn" onclick="openPanel()" aria-label="Settings">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>

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
    <div class="plot-cell locked-plot-cell">
      <div class="plot-label" style="filter:blur(3px)">Velocity vs Time (m/s)</div>
      <div class="lock-overlay" style="position:absolute;inset:0;background:rgba(var(--bg-primary-rgb,26,29,35),0.82);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;z-index:5">
        <div class="lock-icon-big" style="font-size:1.4rem">🔒</div>
        <div class="lock-title" style="font-size:0.7rem">PRO</div>
        <a href="/upgrade" class="upgrade-btn" style="padding:7px 16px;font-size:0.62rem" onclick="handleUpgrade(event)">Upgrade</a>
      </div>
    </div>
    <div class="plot-cell locked-plot-cell">
      <div class="plot-label" style="filter:blur(3px)">Buoyancy vs Gravity (N)</div>
      <div class="lock-overlay" style="position:absolute;inset:0;background:rgba(var(--bg-primary-rgb,26,29,35),0.82);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;z-index:5">
        <div class="lock-icon-big" style="font-size:1.4rem">🔒</div>
        <div class="lock-title" style="font-size:0.7rem">PRO</div>
        <a href="/upgrade" class="upgrade-btn" style="padding:7px 16px;font-size:0.62rem" onclick="handleUpgrade(event)">Upgrade</a>
      </div>
    </div>
    <div class="plot-cell locked-plot-cell">
      <div class="plot-label" style="filter:blur(3px)">Pressure vs Depth (atm)</div>
      <div class="lock-overlay" style="position:absolute;inset:0;background:rgba(var(--bg-primary-rgb,26,29,35),0.82);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;z-index:5">
        <div class="lock-icon-big" style="font-size:1.4rem">🔒</div>
        <div class="lock-title" style="font-size:0.7rem">PRO</div>
        <a href="/upgrade" class="upgrade-btn" style="padding:7px 16px;font-size:0.62rem" onclick="handleUpgrade(event)">Upgrade</a>
      </div>
    </div>
  </div>
</div>

<!-- ── DATA PANEL ────────────────────────────────────────────────────────── -->
<div class="panel" id="panelData">
  <div class="data-panel-inner">
    <div class="data-toolbar">
      <button class="csv-btn" style="opacity:0.35;pointer-events:none;filter:blur(1px)" disabled>Export CSV</button>
      <span class="data-row-count" id="dataRowCount">0 / 10 rows (trial)</span>
      <span style="margin-left:auto;font-family:var(--font-mono);font-size:0.55rem;color:var(--color-accent)">
        <a href="/upgrade" onclick="handleUpgrade(event)" style="color:var(--color-accent);text-decoration:none">Upgrade for full export &amp; 500 rows</a>
      </span>
    </div>
    <div class="table-wrap" id="tableWrap" style="max-height:none;overflow:hidden">
      <table id="dataTable">
        <thead>
          <tr>
            <th>t (s)</th><th>depth (m)</th><th>vel (m/s)</th><th>accel (m/s²)</th>
            <th>Fb (N)</th><th>Fg (N)</th><th>Fd (N)</th><th>P (atm)</th>
            <th>Re</th><th>Cd_eff</th><th>stress (MPa)</th><th>stress %</th><th>status</th>
          </tr>
        </thead>
        <tbody id="dataTbody"></tbody>
      </table>
    </div>
    <div class="locked-panel-inner" style="position:relative;min-height:120px;margin-top:0">
      <div class="lock-overlay" style="position:relative;inset:auto;padding:18px 24px;background:linear-gradient(to bottom, transparent, var(--bg-secondary));backdrop-filter:none;-webkit-backdrop-filter:none">
        <div class="lock-icon-big" style="font-size:1.4rem">🔒</div>
        <div class="lock-title">Full Data Export — PRO</div>
        <div class="lock-sub">Trial shows 10 rows. Upgrade for 500 rows, CSV export, and all physics columns.</div>
        <a href="/upgrade" class="upgrade-btn" onclick="handleUpgrade(event)">⚡ Upgrade to PRO</a>
      </div>
    </div>
  </div>
</div>

<script>
// ── Constants ─────────────────────────────────────────────────────────────────
const API_PATH             = '?_engine=1';
const PHYSICS_BATCH_SIZE   = 120;          // states per server fetch
const PHYSICS_STEP_MS      = 50;           // wall ms between physics states (= dt * 1000)
const PHYSICS_STEP_SECONDS = 0.05;
const MAX_TABLE_ROWS       = 10;           // trial cap — PRO allows 500
const MAX_HISTORY_POINTS   = 2400;         // plot / analysis history cap
const DEVICE_PIXEL_RATIO   = window.devicePixelRatio || 1;
const MAX_OCEAN_DEPTH_M    = 11000;        // Challenger Deep
const VIEWPORT_HEIGHT_M    = 600;          // metres visible in canvas height
const SUB_SCREEN_FRACTION  = 0.30;         // sub sits this far down from top

// ── Simulation parameters (mutated by controls) ───────────────────────────────
const simParams = {
  gravity:      9.81,
  rho_fluid:    1025,
  rho_hull:     7800,
  length:       80,
  diameter:     10,
  wall_thick:   0.05,
  ballast_frac: 0,
  cd:           0.3,
  yield_mpa:    250,
};

// ── Presets ───────────────────────────────────────────────────────────────────
// wall_thick = t_wall / r_outer. Crush depths from Von Mises thin-wall at SF=1.0.
const VESSEL_PRESETS = {
  attack:   { length:98,   diameter:9.65,  wall_thick:0.0079, rho_hull:7850, ballast_frac:0.15, cd:0.20, yield_mpa:690 }, // crush ~545 m
  boomer:   { length:175,  diameter:13.0,  wall_thick:0.0074, rho_hull:7850, ballast_frac:0.20, cd:0.25, yield_mpa:690 }, // crush ~511 m
  dsp:      { length:40,   diameter:7.0,   wall_thick:0.0100, rho_hull:8000, ballast_frac:0.10, cd:0.35, yield_mpa:880 }, // crush ~884 m
  thresher: { length:84,   diameter:9.65,  wall_thick:0.0079, rho_hull:7850, ballast_frac:0.10, cd:0.22, yield_mpa:552 }, // crush ~436 m (test depth 396 m, SF=1.10)
  titan:    { length:6.7,  diameter:1.674, wall_thick:0.1517, rho_hull:1600, ballast_frac:0.05, cd:0.50, yield_mpa:189 }, // crush ~3362 m (fatigue-degraded CFRP, 31% of nominal 600 MPa)
};

// ── Hull material library ─────────────────────────────────────────────────────
const HULL_MATERIALS = {
  '250': { densityKgM3: 7800, label: 'Mild Steel'          },
  '690': { densityKgM3: 7850, label: 'High-Strength Steel' },
  '503': { densityKgM3: 2810, label: 'Aluminium 7075'      },
  '880': { densityKgM3: 4430, label: 'Titanium Ti-6Al-4V'  },
  '600': { densityKgM3: 1600, label: 'Carbon Composite'    },
};

// ── Named depth reference lines ───────────────────────────────────────────────
const OCEAN_DEPTH_MARKERS = [
  { depthM: 396,   label: 'Thresher test depth',     color: 'rgba(240,192,64,0.55)'  },
  { depthM: 500,   label: 'Military op. limit',       color: 'rgba(255,255,255,0.25)' },
  { depthM: 910,   label: 'USS Dolphin record',       color: 'rgba(255,255,255,0.25)' },
  { depthM: 1020,  label: 'K-278 Komsomolets',        color: 'rgba(255,255,255,0.25)' },
  { depthM: 2560,  label: 'USS Thresher implosion',   color: 'rgba(220,53,69,0.55)'   },
  { depthM: 3363,  label: 'Titan implosion',          color: 'rgba(220,53,69,0.55)'   },
  { depthM: 3800,  label: 'Titanic wreck',            color: 'rgba(100,180,255,0.45)' },
  { depthM: 10928, label: 'Challenger Deep',          color: 'rgba(23,162,184,0.55)'  },
];

// ── Mutable simulation state ──────────────────────────────────────────────────
let startDepthM     = 0;      // initial depth set by user (unchanged during run)
let renderDepthM    = 0;      // depth shown on canvas — updated only from buffer
let renderVelocity  = 0;      // velocity shown on canvas — updated only from buffer
let fetchCursorY    = 0;      // depth fed to next fetch — updated from server response
let fetchCursorVy   = 0;      // velocity fed to next fetch
let simTimeSeconds  = 0;      // elapsed simulation time

let physicsBuffer     = [];
let physicsBufferHead = 0;
let isFetching        = false;
let hasImploded       = false;

let depthHistory = [], velocityHistory = [], forceHistory = [], pressureHistory = [];
let latestStaticInfo  = {};
let activeTabName     = 'sim';
let cachedThemeColors = null;
let neutralDepthM     = null;
let flowRateMs        = 5.0;  // default matches slider starting value
let isFlowVisible     = false;

// Flow streamline state — rebuilt only when flow rate changes significantly
let streamlinePoints    = [];
let flowParticles       = [];
let streamlineNeedsRebuild = true;
let lastBuiltFlowRate   = -1;
let lastBuiltVelocity   = null;

// Render loop timing
let lastFrameTimestamp  = 0;
let accumulatedPhysicsMs = 0;

// Data table state
let tableRowBuffer  = [];
let tableDomRowCount = 0;

// Implosion animation state
let implosionStartTs    = 0;
let implosionPhase      = 'none';  // 'none' | 'animating' | 'done'
let implosionDepthM     = 0;
let implosionFragments  = [];

// Canvas
const simCanvas = document.getElementById('simCanvas');
const ctx       = simCanvas.getContext('2d');

// ── Theme colours ─────────────────────────────────────────────────────────────
function buildThemeColors() {
  const style = getComputedStyle(document.documentElement);
  const get   = name => style.getPropertyValue(name).trim();
  cachedThemeColors = {
    bg:      get('--bg-primary'),
    muted:   get('--text-muted'),
    accent:  get('--color-accent'),
    info:    get('--color-info'),
    danger:  get('--color-danger'),
    success: get('--color-success'),
    border:  get('--border-color'),
  };
}
function themeColors() {
  if (!cachedThemeColors) buildThemeColors();
  return cachedThemeColors;
}
function isDarkTheme() {
  return document.documentElement.getAttribute('data-theme') !== 'light';
}

// ── Canvas sizing ─────────────────────────────────────────────────────────────
function resizeSim() {
  const zone   = simCanvas.parentElement;
  const width  = zone.clientWidth;
  const height = zone.clientHeight;
  simCanvas.width        = width  * DEVICE_PIXEL_RATIO;
  simCanvas.height       = height * DEVICE_PIXEL_RATIO;
  simCanvas.style.width  = width  + 'px';
  simCanvas.style.height = height + 'px';
  ctx.setTransform(DEVICE_PIXEL_RATIO, 0, 0, DEVICE_PIXEL_RATIO, 0, 0);
  streamlineNeedsRebuild = true;
}
window.addEventListener('resize', () => {
  resizeSim();
  streamlineNeedsRebuild = true;
  if (activeTabName === 'anal') resizePlots();
});

// ── Camera / layout ───────────────────────────────────────────────────────────

/**
 * Returns the canvas layout for the current frame.
 * The camera follows the submarine so it stays at SUB_SCREEN_FRACTION
 * from the top, but never scrolls so far that the surface goes below surfacePixelY.
 */
function computeLayout() {
  const canvasWidth   = simCanvas.clientWidth  || simCanvas.offsetWidth  || 300;
  const canvasHeight  = simCanvas.clientHeight || simCanvas.offsetHeight || 200;
  const oceanPixels   = canvasHeight * 0.88;
  const pixelsPerMetre = oceanPixels / VIEWPORT_HEIGHT_M;
  const surfacePixelY = canvasHeight * 0.08;

  const minCameraTop = -surfacePixelY / pixelsPerMetre;
  const cameraTop    = Math.max(
    minCameraTop,
    renderDepthM - SUB_SCREEN_FRACTION * VIEWPORT_HEIGHT_M
  );

  return { canvasWidth, canvasHeight, pixelsPerMetre, surfacePixelY, cameraTop };
}

/** Converts a world depth (metres, +ve = below surface) to a canvas Y pixel. */
function depthToCanvasY(depthM, surfacePixelY, pixelsPerMetre, cameraTop) {
  return surfacePixelY + (depthM - cameraTop) * pixelsPerMetre;
}

// ── Sub geometry ─────────────────────────────────────────────────────────────

/** Returns pixel dimensions of the submarine hull for the current canvas size. */
function computeSubPixelSize(canvasWidth, canvasHeight) {
  const scaleFactor  = Math.min(
    (canvasWidth  * 0.52) / simParams.length,
    (canvasHeight * 0.10) / simParams.diameter,
    1.5
  );
  return {
    widthPx:  simParams.length   * scaleFactor,
    heightPx: simParams.diameter * scaleFactor,
  };
}

// ── Draw: background layers ───────────────────────────────────────────────────

function drawSkyAndOcean(layout, surfaceY) {
  const { canvasWidth, canvasHeight } = layout;
  const dark = isDarkTheme();

  const skyHeight = Math.max(0, surfaceY);
  if (skyHeight > 0) {
    ctx.fillStyle = dark ? '#07080f' : '#c8e8f8';
    ctx.fillRect(0, 0, canvasWidth, skyHeight);
  }

  const gradient = ctx.createLinearGradient(0, surfaceY, 0, canvasHeight);
  gradient.addColorStop(0,   dark ? '#0a1628' : '#cde8ff');
  gradient.addColorStop(0.5, dark ? '#040d1a' : '#6aaad4');
  gradient.addColorStop(1,   dark ? '#010408' : '#1a3a58');
  ctx.fillStyle = gradient;
  ctx.fillRect(0, Math.max(0, surfaceY), canvasWidth, canvasHeight - Math.max(0, surfaceY));
}

function drawDepthMarkers(layout, surfaceY) {
  const { canvasWidth, canvasHeight, pixelsPerMetre, cameraTop } = layout;
  const fontSize = Math.max(8, Math.min(11, canvasWidth * 0.018));
  ctx.font = `${fontSize}px var(--font-mono, monospace)`;

  for (const marker of OCEAN_DEPTH_MARKERS) {
    const markerY = depthToCanvasY(marker.depthM, surfaceY, pixelsPerMetre, cameraTop);
    if (markerY < -2 || markerY > canvasHeight + 2) continue;
    ctx.strokeStyle = marker.color;
    ctx.lineWidth   = 1;
    ctx.setLineDash([5, 4]);
    ctx.beginPath();
    ctx.moveTo(0, markerY);
    ctx.lineTo(canvasWidth, markerY);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = marker.color.replace(/[\d.]+\)$/, '0.9)');
    ctx.fillText(`${marker.depthM}m  ${marker.label}`, 6, markerY - 2);
  }
}

function drawDepthRuler(layout, surfaceY) {
  const { canvasWidth, canvasHeight, pixelsPerMetre, cameraTop } = layout;
  const fontSize     = Math.max(7, Math.min(10, canvasWidth * 0.017));
  const rulerRightX  = canvasWidth - 50;
  const visibleRange = canvasHeight / pixelsPerMetre;
  const tickInterval = visibleRange < 200  ? 50
                     : visibleRange < 1000 ? 200
                     : visibleRange < 5000 ? 1000
                     : 2000;
  const firstTick    = Math.ceil(cameraTop / tickInterval) * tickInterval;

  ctx.font = `${fontSize}px var(--font-mono, monospace)`;
  for (let depth = firstTick; depth <= cameraTop + visibleRange; depth += tickInterval) {
    if (depth < 0 || depth > MAX_OCEAN_DEPTH_M) continue;
    const tickY = depthToCanvasY(depth, surfaceY, pixelsPerMetre, cameraTop);
    if (tickY < 0 || tickY > canvasHeight) continue;
    ctx.strokeStyle = 'rgba(255,255,255,0.10)';
    ctx.lineWidth   = 1;
    ctx.beginPath();
    ctx.moveTo(rulerRightX, tickY);
    ctx.lineTo(rulerRightX + 12, tickY);
    ctx.stroke();
    const label = depth >= 1000 ? `${(depth / 1000).toFixed(0)}km` : `${depth}m`;
    ctx.fillStyle = isDarkTheme() ? 'rgba(255,255,255,0.28)' : 'rgba(0,0,0,0.30)';
    ctx.fillText(label, rulerRightX - 38, tickY + 3);
  }
}

function drawSurfaceLine(layout, surfaceY) {
  const { canvasWidth, canvasHeight } = layout;
  if (surfaceY < 0 || surfaceY > canvasHeight) return;
  ctx.strokeStyle = isDarkTheme() ? 'rgba(23,162,184,0.65)' : 'rgba(10,90,160,0.7)';
  ctx.lineWidth   = 1.5;
  ctx.setLineDash([10, 5]);
  ctx.beginPath();
  ctx.moveTo(0, surfaceY);
  ctx.lineTo(canvasWidth, surfaceY);
  ctx.stroke();
  ctx.setLineDash([]);
}

function drawNeutralDepthLine(layout, surfaceY) {
  if (neutralDepthM === null || neutralDepthM < 0) return;
  const { canvasWidth, canvasHeight, pixelsPerMetre, cameraTop } = layout;
  const markerY = depthToCanvasY(neutralDepthM, surfaceY, pixelsPerMetre, cameraTop);
  if (markerY < 0 || markerY > canvasHeight) return;

  ctx.strokeStyle = isDarkTheme() ? 'rgba(240,192,64,0.6)' : 'rgba(200,140,0,0.7)';
  ctx.lineWidth   = 1.5;
  ctx.setLineDash([6, 4]);
  ctx.beginPath();
  ctx.moveTo(0, markerY);
  ctx.lineTo(canvasWidth, markerY);
  ctx.stroke();
  ctx.setLineDash([]);

  const fontSize = Math.max(8, Math.min(11, canvasWidth * 0.018));
  ctx.font      = `${fontSize}px var(--font-mono, monospace)`;
  ctx.fillStyle  = isDarkTheme() ? 'rgba(240,192,64,0.85)' : 'rgba(180,120,0,0.9)';
  ctx.fillText(`neutral depth: ${neutralDepthM}m`, 6, markerY - 3);
}

// ── Draw: submarine ───────────────────────────────────────────────────────────

function drawSubmarine(layout, surfaceY) {
  const { canvasWidth, canvasHeight, pixelsPerMetre, cameraTop } = layout;
  const subCentreY            = depthToCanvasY(renderDepthM, surfaceY, pixelsPerMetre, cameraTop);
  const { widthPx, heightPx } = computeSubPixelSize(canvasWidth, canvasHeight);
  const halfHeight            = heightPx / 2;
  const outerRadius           = simParams.diameter / 2;

  ctx.save();
  ctx.translate(canvasWidth / 2, subCentreY);

  // Clip to ocean when hull straddles the surface
  const isCrossingSurface = renderDepthM > -outerRadius && renderDepthM < outerRadius;
  if (isCrossingSurface) {
    ctx.save();
    const surfaceInLocalCoords = surfaceY - subCentreY;
    ctx.beginPath();
    ctx.rect(-widthPx, surfaceInLocalCoords, widthPx * 2, canvasHeight * 2);
    ctx.clip();
  }

  // Hull
  const hullGradient = ctx.createLinearGradient(0, -halfHeight, 0, halfHeight);
  hullGradient.addColorStop(0,    '#8090a0');
  hullGradient.addColorStop(0.45, '#5a6878');
  hullGradient.addColorStop(1,    '#3a4858');
  ctx.fillStyle = hullGradient;
  ctx.beginPath();
  ctx.ellipse(0, 0, widthPx / 2, halfHeight, 0, 0, Math.PI * 2);
  ctx.fill();
  ctx.strokeStyle = '#9aabbb';
  ctx.lineWidth   = 1.2;
  ctx.stroke();

  if (isCrossingSurface) ctx.restore();

  // Conning tower
  ctx.fillStyle   = '#4a5868';
  ctx.beginPath();
  ctx.rect(-widthPx * 0.05, -halfHeight - heightPx * 0.35, widthPx * 0.10, heightPx * 0.38);
  ctx.fill();
  ctx.strokeStyle = '#7a8898';
  ctx.lineWidth   = 0.8;
  ctx.stroke();

  ctx.restore();
  return { subCentreY, widthPx };
}

// ── Draw: force arrows ────────────────────────────────────────────────────────

function drawForceArrow(fromX, fromY, toX, toY, color, label) {
  const length = Math.hypot(toX - fromX, toY - fromY);
  if (length < 3) return;

  ctx.strokeStyle = color;
  ctx.lineWidth   = 2;
  ctx.beginPath();
  ctx.moveTo(fromX, fromY);
  ctx.lineTo(toX, toY);
  ctx.stroke();

  const angle      = Math.atan2(toY - fromY, toX - fromX);
  const arrowSize  = 7;
  ctx.fillStyle    = color;
  ctx.beginPath();
  ctx.moveTo(toX, toY);
  ctx.lineTo(toX - arrowSize * Math.cos(angle - 0.42), toY - arrowSize * Math.sin(angle - 0.42));
  ctx.lineTo(toX - arrowSize * Math.cos(angle + 0.42), toY - arrowSize * Math.sin(angle + 0.42));
  ctx.closePath();
  ctx.fill();

  ctx.font      = '9px var(--font-mono, monospace)';
  ctx.fillStyle = color;
  ctx.fillText(label, fromX + 4, fromY - 5);
}

function drawForceArrows(layout, subCentreY, subWidthPx) {
  const { canvasWidth, canvasHeight } = layout;
  const buoyancyForce = latestStaticInfo.Fb || 0;
  const weightForce   = latestStaticInfo.Fg || 0;
  if (buoyancyForce === 0 && weightForce === 0) return;
  if (subCentreY < -50 || subCentreY > canvasHeight + 50) return;

  const arrowBaseX = canvasWidth / 2 + subWidthPx / 2 + 22;
  const maxForce   = Math.max(buoyancyForce, weightForce, 1);
  const maxLength  = Math.min(canvasHeight * 0.20, 70);

  drawForceArrow(
    arrowBaseX, subCentreY,
    arrowBaseX, subCentreY - (buoyancyForce / maxForce) * maxLength,
    '#17a2b8', 'Fb'
  );
  drawForceArrow(
    arrowBaseX + 22, subCentreY,
    arrowBaseX + 22, subCentreY + (weightForce / maxForce) * maxLength,
    '#dc3545', 'Fg'
  );
}

// ── Draw: HUD and stress bar ──────────────────────────────────────────────────

function updateHud() {
  const depthBelow    = Math.max(0, renderDepthM);
  const pressureAtm   = 1 + depthBelow / 10.2;
  const stressRatio   = latestStaticInfo.lastStressRatio || 0;
  const stressMPa     = stressRatio * simParams.yield_mpa;
  const buoyancyForce = latestStaticInfo.Fb || 0;
  const weightForce   = latestStaticInfo.Fg || 0;

  document.getElementById('hudDepth').textContent  = `${depthBelow.toFixed(2)} m`;
  document.getElementById('hudVel').textContent    = `${renderVelocity.toFixed(3)} m/s`;
  document.getElementById('hudP').textContent      = `${pressureAtm.toFixed(2)} atm`;
  document.getElementById('hudStress').textContent = `${stressMPa.toFixed(1)} MPa`;

  if (buoyancyForce > 0 && weightForce > 0) {
    document.getElementById('hudRatio').textContent = (buoyancyForce / weightForce).toFixed(3);
  }

  // Neutral buoyancy tooltip on status badge
  const neutralBallast = latestStaticInfo.ballastNeutral;
  if (neutralBallast !== null && neutralBallast !== undefined && neutralBallast <= 1) {
    document.getElementById('statusBadge').title =
      `Neutral buoyancy at ballast ${(neutralBallast * 100).toFixed(1)}%`;
  }
}

function updateStressBar() {
  const stressRatio = Math.min(1, latestStaticInfo.lastStressRatio || 0);
  const fillEl      = document.getElementById('stressBarFill');
  fillEl.style.width = `${(stressRatio * 100).toFixed(1)}%`;
  fillEl.className   = 'stress-bar-fill'
    + (stressRatio > 0.85 ? ' danger' : stressRatio > 0.60 ? ' warn' : '');
  document.getElementById('stressBarPct').textContent = `${(stressRatio * 100).toFixed(1)}%`;
}

// ── Draw: flow visualisation ──────────────────────────────────────────────────

/**
 * Builds streamlines in LOCAL hull space (centre = 0,0).
 * The doublet solution for uniform horizontal flow past an ellipse gives
 * analytically correct streamlines that bend tightly around the beam and
 * spread fore/aft at stagnation points.
 *
 * Stored as local-space {x,y} — translated to canvas each frame in
 * drawFlowVisualisation, so lines follow the sub as it moves.
 *
 * Rebuilt only when hull pixel dimensions change (resize or preset change).
 */
function rebuildStreamlines() {
  const layout = computeLayout();
  const { canvasWidth, canvasHeight } = layout;
  if (!canvasWidth || !canvasHeight) return;

  const { widthPx, heightPx } = computeSubPixelSize(canvasWidth, canvasHeight);
  const a = widthPx  / 2;   // semi-axis horizontal
  const b = heightPx / 2;   // semi-axis vertical

  // Equivalent circle radius for the doublet (geometric mean of axes)
  const R = Math.sqrt(a * b);

  const N_LINES  = 18;
  const POINTS   = 120;
  // Cover ±3b vertically, ±2.8a horizontally
  const xSpan    = a * 2.8;
  const ySpan    = b * 3.0;

  streamlinePoints = [];

  for (let li = 0; li < N_LINES; li++) {
    // Evenly spaced y-offsets across the full vertical span — symmetric top/bottom
    const yFrac  = (li + 0.5) / N_LINES;
    const yLocal = -ySpan + yFrac * ySpan * 2;

    const points = [];
    for (let pi = 0; pi < POINTS; pi++) {
      const xLocal = -xSpan + (pi / (POINTS - 1)) * xSpan * 2;

      // Stretch to circle space for the doublet solution
      const xC = xLocal;
      const yC = yLocal * (a / b);    // scale y so ellipse becomes circle of radius a

      const r2 = xC * xC + yC * yC;
      const R2 = R * R;

      // Doublet + uniform flow: exact streamline coords in circle space
      const xD = r2 > 1e-6 ? xC + R2 * xC / r2 : xC;
      const yD = r2 > 1e-6 ? yC - R2 * yC / r2 : yC;

      // Scale back to ellipse space
      const xFinal = xD;
      const yFinal = yD * (b / a);

      // Discard points inside the hull ellipse (with small margin)
      if ((xFinal / a) ** 2 + (yFinal / b) ** 2 < 1.06) continue;

      // Discard points too far from the hull — keeps lines tight around body
      if (Math.abs(xFinal) > xSpan * 1.1 || Math.abs(yFinal) > ySpan * 1.1) continue;

      points.push({ x: xFinal, y: yFinal });
    }
    if (points.length > 4) streamlinePoints.push(points);
  }

  // Evenly spaced particles for continuous-looking flow from frame one
  const N_PARTICLES = 180;
  flowParticles = Array.from({ length: N_PARTICLES }, (_, i) => ({
    streamlineIndex: i % streamlinePoints.length,
    progress:        i / N_PARTICLES,
  }));

  streamlineNeedsRebuild = false;
  lastBuiltFlowRate      = flowRateMs;
  lastBuiltVelocity      = renderVelocity;
}

function drawFlowVisualisation(layout, subCentreY) {
  if (!isFlowVisible) return;

  // Rebuild when hull geometry changes (resize/preset) — depth change does not require rebuild
  if (streamlineNeedsRebuild || lastBuiltFlowRate !== flowRateMs) {
    rebuildStreamlines();
  }
  if (!streamlinePoints.length) return;

  const { canvasWidth } = layout;
  const subCentreX      = canvasWidth / 2;

  ctx.save();
  // Translate to sub centre — all streamline coords are in local hull space
  ctx.translate(subCentreX, subCentreY);

  // Faint static streamlines showing the steady-state flow pattern
  ctx.strokeStyle = 'rgba(23,162,184,0.13)';
  ctx.lineWidth   = 0.8;
  for (const line of streamlinePoints) {
    if (line.length < 2) continue;
    ctx.beginPath();
    ctx.moveTo(line[0].x, line[0].y);
    for (let i = 1; i < line.length; i++) ctx.lineTo(line[i].x, line[i].y);
    ctx.stroke();
  }

  // Animated particles travelling along streamlines
  const advancePerFrame = (flowRateMs / 20) * 0.018;

  for (const particle of flowParticles) {
    const line = streamlinePoints[particle.streamlineIndex];
    if (!line || line.length < 2) continue;

    particle.progress += advancePerFrame;
    if (particle.progress > 1) particle.progress -= 1;

    const rawIndex  = particle.progress * (line.length - 1);
    const baseIndex = Math.floor(rawIndex);
    const lerp      = rawIndex - baseIndex;
    const nextIndex = Math.min(baseIndex + 1, line.length - 1);

    const px = line[baseIndex].x + (line[nextIndex].x - line[baseIndex].x) * lerp;
    const py = line[baseIndex].y + (line[nextIndex].y - line[baseIndex].y) * lerp;

    // Fade near streamline ends
    const edgeFade = Math.min(particle.progress * 5, 1, (1 - particle.progress) * 5);
    const alpha    = 0.9 * edgeFade;
    if (alpha < 0.02) continue;

    ctx.beginPath();
    ctx.arc(px, py, 2.2, 0, Math.PI * 2);
    ctx.fillStyle = `rgba(23,162,184,${alpha.toFixed(2)})`;
    ctx.fill();
  }

  ctx.restore();
}

// ── Draw: implosion animation ─────────────────────────────────────────────────

function spawnImplosionFragments(centreX, centreY) {
  implosionFragments = Array.from({ length: 24 }, (_, i) => {
    const angle = (i / 24) * Math.PI * 2;
    const speed = 2 + Math.random() * 5;
    return {
      x: centreX, y: centreY,
      vx: Math.cos(angle) * speed,
      vy: Math.sin(angle) * speed - 1,
      size:  3 + Math.random() * 6,
      alpha: 1,
      color: Math.random() > 0.5 ? '#8090a0' : '#dc3545',
    };
  });
}

function triggerImplosion(depthM) {
  implosionDepthM  = depthM;
  implosionStartTs = performance.now();
  implosionPhase   = 'animating';

  const layout     = computeLayout();
  const surfaceY   = layout.surfacePixelY;
  const centreY    = depthToCanvasY(depthM, surfaceY, layout.pixelsPerMetre, layout.cameraTop);
  spawnImplosionFragments(layout.canvasWidth / 2, centreY);
}

function drawImplosionAnimation(timestamp) {
  if (implosionPhase !== 'animating') return;

  const elapsed  = timestamp - implosionStartTs;
  const duration = 1800; // ms

  if (elapsed > duration) {
    implosionPhase = 'done';
    document.getElementById('implosionOverlay').classList.add('active');
    return;
  }

  const layout   = computeLayout();
  const surfaceY = layout.surfacePixelY;
  const centreY  = depthToCanvasY(implosionDepthM, surfaceY, layout.pixelsPerMetre, layout.cameraTop);
  const progress = elapsed / duration;
  const { canvasWidth, canvasHeight } = layout;

  // Expanding shockwave rings
  const shockwaveRadius = progress * Math.min(canvasWidth, canvasHeight) * 0.45;
  const ringAlpha       = Math.max(0, 0.7 - progress * 0.9);

  ctx.beginPath();
  ctx.arc(canvasWidth / 2, centreY, shockwaveRadius, 0, Math.PI * 2);
  ctx.strokeStyle = `rgba(220,53,69,${ringAlpha})`;
  ctx.lineWidth   = 3 * (1 - progress);
  ctx.stroke();

  if (progress > 0.1) {
    const secondRingRadius = (progress - 0.1) * Math.min(canvasWidth, canvasHeight) * 0.3;
    ctx.beginPath();
    ctx.arc(canvasWidth / 2, centreY, secondRingRadius, 0, Math.PI * 2);
    ctx.strokeStyle = `rgba(255,100,50,${ringAlpha * 0.5})`;
    ctx.lineWidth   = 2;
    ctx.stroke();
  }

  // Flying fragments
  for (const frag of implosionFragments) {
    frag.x    += frag.vx;
    frag.y    += frag.vy;
    frag.vy   += 0.15;  // gravity on fragments
    frag.alpha = Math.max(0, 1 - progress * 1.2);

    ctx.globalAlpha = frag.alpha;
    ctx.fillStyle   = frag.color;
    ctx.beginPath();
    ctx.rect(frag.x - frag.size / 2, frag.y - frag.size / 2, frag.size, frag.size);
    ctx.fill();
  }
  ctx.globalAlpha = 1;
}

// ── Main draw ─────────────────────────────────────────────────────────────────

function drawSim() {
  const layout     = computeLayout();
  const surfaceY   = depthToCanvasY(0, layout.surfacePixelY, layout.pixelsPerMetre, layout.cameraTop);
  const { canvasWidth, canvasHeight } = layout;

  ctx.clearRect(0, 0, canvasWidth, canvasHeight);

  drawSkyAndOcean(layout, surfaceY);
  drawDepthMarkers(layout, surfaceY);
  drawDepthRuler(layout, surfaceY);
  drawSurfaceLine(layout, surfaceY);
  drawNeutralDepthLine(layout, surfaceY);

  const { subCentreY, widthPx } = drawSubmarine(layout, surfaceY);
  drawForceArrows(layout, subCentreY, widthPx);
  drawFlowVisualisation(layout, subCentreY);

  updateHud();
  updateStressBar();
}

// ── Fetch ─────────────────────────────────────────────────────────────────────

async function fetchPhysicsBatch() {
  if (isFetching || hasImploded) return;
  isFetching = true;
  try {
    const requestBody = JSON.stringify({
      ...simParams,
      steps: PHYSICS_BATCH_SIZE,
      dt:    PHYSICS_STEP_SECONDS,
      y:     fetchCursorY,
      vy:    fetchCursorVy,
    });
    const response = await fetch(API_PATH, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: requestBody,
    });
    const json     = await response.json();

    if (json.states?.length) physicsBuffer.push(...json.states);

    if (json.static) {
      latestStaticInfo = { ...latestStaticInfo, ...json.static };
      updateStatusBadge(json.static.status);
    }

    // Fetch cursor advances independently of render cursor — prevents teleporting
    if (json.finalY !== undefined) {
      fetchCursorY  = json.finalY;
      fetchCursorVy = json.finalVy;
    }
  } catch {
    runClientFallback();
  } finally {
    isFetching = false;
  }
}

/**
 * JavaScript physics fallback when server is unavailable.
 * Mirrors the PHP engine exactly so behaviour is identical offline.
 */
function runClientFallback() {
  const g       = simParams.gravity;
  const rhoF    = simParams.rho_fluid;
  const outerR  = simParams.diameter / 2;
  const innerR  = outerR * (1 - simParams.wall_thick);
  const tWall   = outerR - innerR;
  const volTot  = Math.PI * outerR ** 2 * simParams.length;
  const volIn   = Math.PI * innerR ** 2 * simParams.length;
  const mHull   = (volTot - volIn) * simParams.rho_hull;
  const mBal    = simParams.ballast_frac * volIn * rhoF;
  const mTotal  = mHull + mBal;
  const Fg      = mTotal * g;
  const Ac      = Math.PI * outerR ** 2;
  const yieldPa = simParams.yield_mpa * 1e6;

  let depth    = fetchCursorY;
  let velocity = fetchCursorVy;

  for (let step = 0; step < PHYSICS_BATCH_SIZE; step++) {
    // Submerged volume
    let volDisp;
    if (depth <= -outerR) {
      volDisp = 0;
    } else if (depth >= outerR) {
      volDisp = volTot;
    } else {
      const h      = depth + outerR;
      const cosArg = Math.max(-1, Math.min(1, (outerR - h) / outerR));
      const segA   = outerR ** 2 * Math.acos(cosArg)
                   - (outerR - h) * Math.sqrt(Math.max(0, 2 * outerR * h - h ** 2));
      volDisp = segA * simParams.length;
    }

    const Fb      = rhoF * g * volDisp;
    const dragCd  = simParams.cd;
    const Fd      = 0.5 * rhoF * dragCd * Ac * velocity * Math.abs(velocity);
    const Fnet    = depth < -outerR ? -Fg : Fb - Fg - Fd;
    const accel   = Fnet / mTotal;

    velocity += accel    * PHYSICS_STEP_SECONDS;
    depth    += velocity * PHYSICS_STEP_SECONDS;

    if (depth < -outerR) { depth = -outerR; if (velocity < 0) velocity = 0; }
    if (depth > 11000)   { depth = 11000;   velocity = 0; }

    const depthBelow  = Math.max(0, depth);
    const pressureAtm = 1 + depthBelow / 10.2;
    const pressurePa  = pressureAtm * 101325;
    const gaugePa     = pressurePa - 101325;
    const stressPa    = tWall > 0 ? Math.abs(gaugePa * innerR / tWall) : 0;
    const stressMPa   = stressPa / 1e6;
    const stressRatio = stressPa / yieldPa;

    physicsBuffer.push([
      +depth.toFixed(4), +velocity.toFixed(5), +accel.toFixed(6),
      +Fb.toFixed(2),    +Fg.toFixed(2),        +Fd.toFixed(4),
      +pressurePa.toFixed(1), +pressureAtm.toFixed(4),
      +mTotal.toFixed(2),     +depthBelow.toFixed(4),
      +stressMPa.toFixed(4),  +stressRatio.toFixed(6),
      +volDisp.toFixed(3),    +dragCd.toFixed(5),
      +(rhoF * Math.abs(velocity) * simParams.diameter / 1e-3).toFixed(1),
    ]);
    if (stressRatio >= 1.0) break;
  }

  const FbMax          = rhoF * g * volTot;
  const neutralBallast = volIn > 0
    ? Math.max(0, Math.min(1, (rhoF * volTot - mHull) / (volIn * rhoF)))
    : null;
  const status         = Fg > FbMax * 1.001 ? 'sinks'
                       : FbMax > Fg * 1.001 ? 'floats'
                       : 'neutral';

  latestStaticInfo = {
    Fb: FbMax, Fg, mTotal, status,
    mHull, mBallast: mBal, rho_eff: mTotal / volTot, ballastNeutral: neutralBallast,
  };
  updateStatusBadge(status);
  fetchCursorY  = depth;
  fetchCursorVy = velocity;
}

// ── Render loop ───────────────────────────────────────────────────────────────

function renderLoop(timestamp) {
  requestAnimationFrame(renderLoop);

  if (implosionPhase === 'animating') {
    drawSim();
    drawImplosionAnimation(timestamp);
    return;
  }
  if (implosionPhase === 'done') return;

  // Keep buffer topped up — fetch before it drains to avoid pauses
  const bufferRemaining = physicsBuffer.length - physicsBufferHead;
  if (!isFetching && !hasImploded && bufferRemaining < PHYSICS_BATCH_SIZE * 0.6) {
    fetchPhysicsBatch();
  }

  // First frame: just record timestamp and draw
  if (lastFrameTimestamp === 0) {
    lastFrameTimestamp = timestamp;
    drawSim();
    return;
  }

  const wallDeltaMs = Math.min(timestamp - lastFrameTimestamp, 200);
  lastFrameTimestamp    = timestamp;
  accumulatedPhysicsMs += wallDeltaMs;

  // Drain physics states at the fixed simulation rate (one state per PHYSICS_STEP_MS)
  while (accumulatedPhysicsMs >= PHYSICS_STEP_MS && physicsBufferHead < physicsBuffer.length) {
    const state = physicsBuffer[physicsBufferHead++];

    renderDepthM   = state[0];
    renderVelocity = state[1];
    simTimeSeconds += PHYSICS_STEP_SECONDS;

    latestStaticInfo.lastStressRatio = state[11];

    recordHistoryPoint(state);
    appendTableRow(state, simTimeSeconds);
    accumulatedPhysicsMs -= PHYSICS_STEP_MS;

    if (state[11] >= 1.0 && !hasImploded) {
      hasImploded = true;
      showImplosionMessage(state[9], state[10], state[11]);
      triggerImplosion(state[9]);
      break;
    }
  }

  drawSim();
  if (activeTabName === 'anal' && depthHistory.length > 0) refreshPlots();
}

function recordHistoryPoint(state) {
  depthHistory.push(state[9]);
  velocityHistory.push(state[1]);
  forceHistory.push([state[3], state[4]]);
  pressureHistory.push([state[9], state[7]]);

  // Drop oldest points when history is full — keeps memory bounded
  if (depthHistory.length > MAX_HISTORY_POINTS) {
    depthHistory.shift();
    velocityHistory.shift();
    forceHistory.shift();
    pressureHistory.shift();
  }
}

function showImplosionMessage(depthM, stressMPa, stressRatio) {
  document.getElementById('implosionSub').textContent =
    `Crush depth exceeded at ${depthM.toFixed(0)} m — `
    + `hull imploded at ${stressMPa.toFixed(0)} MPa `
    + `(${(stressRatio * 100).toFixed(0)}% of yield)`;
}

// ── Data table ────────────────────────────────────────────────────────────────

function appendTableRow(state, simTime) {
  const wasFull = tableRowBuffer.length >= MAX_TABLE_ROWS;
  if (wasFull) tableRowBuffer.shift();
  tableRowBuffer.push(state.concat([simTime]));

  // Throttle DOM updates to every 10 steps when user is not on the data tab
  const shouldRebuild = tableRowBuffer.length % 10 === 0 || activeTabName === 'data';
  if (shouldRebuild) rebuildTableDom(wasFull);
}

function rebuildTableDom(oldestRowWasDropped) {
  if (tableRowBuffer.length === tableDomRowCount && !oldestRowWasDropped) return;

  const tbody  = document.getElementById('dataTbody');
  const status = latestStaticInfo.status || '—';

  if (oldestRowWasDropped) {
    // Remove oldest DOM row to mirror the buffer shift — O(1) vs full rebuild
    if (tbody.firstChild) tbody.removeChild(tbody.firstChild);
    tableDomRowCount = Math.max(0, tableDomRowCount - 1);
  }

  const fragment = document.createDocumentFragment();
  for (let i = tableDomRowCount; i < tableRowBuffer.length; i++) {
    const s = tableRowBuffer[i];
    const t = s[15] ?? (i * PHYSICS_STEP_SECONDS);
    const row = document.createElement('tr');
    row.innerHTML =
      `<td>${t.toFixed(2)}</td>`        +  // time
      `<td>${s[9].toFixed(2)}</td>`     +  // depth
      `<td>${s[1].toFixed(4)}</td>`     +  // velocity
      `<td>${s[2].toFixed(5)}</td>`     +  // acceleration
      `<td>${s[3].toFixed(0)}</td>`     +  // Fb
      `<td>${s[4].toFixed(0)}</td>`     +  // Fg
      `<td>${s[5].toFixed(2)}</td>`     +  // Fd
      `<td>${s[7].toFixed(3)}</td>`     +  // pressure atm
      `<td>${s[14].toFixed(0)}</td>`    +  // Re
      `<td>${s[13].toFixed(4)}</td>`    +  // Cd_eff
      `<td>${s[10].toFixed(3)}</td>`    +  // stress MPa
      `<td>${(s[11]*100).toFixed(2)}%</td>` +
      `<td>${status}</td>`;
    fragment.appendChild(row);
  }
  tbody.appendChild(fragment);
  tableDomRowCount = tableRowBuffer.length;

  document.getElementById('dataRowCount').textContent =
    `${tableRowBuffer.length} / ${MAX_TABLE_ROWS} rows (trial)`;

  // Preserve user scroll position — only follow latest row if already at bottom
  const tableWrap = document.getElementById('tableWrap');
  const isAtBottom = tableWrap.scrollHeight - tableWrap.scrollTop - tableWrap.clientHeight < 40;
  if (isAtBottom) tableWrap.scrollTop = tableWrap.scrollHeight;
}

function exportCSV() {
  // Export disabled in trial — redirect to upgrade
  handleUpgrade(new Event('click'));
}

// ── Plots ─────────────────────────────────────────────────────────────────────

function resizePlotCanvas(canvasId) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const cell   = canvas.parentElement;
  const width  = cell.clientWidth;
  const height = cell.clientHeight;
  if (width < 2 || height < 2) return;
  canvas.width        = width  * DEVICE_PIXEL_RATIO;
  canvas.height       = height * DEVICE_PIXEL_RATIO;
  canvas.style.width  = `${width}px`;
  canvas.style.height = `${height}px`;
  canvas.getContext('2d').setTransform(DEVICE_PIXEL_RATIO, 0, 0, DEVICE_PIXEL_RATIO, 0, 0);
}
function resizePlots() {
  ['cDepth', 'cVel', 'cForce', 'cPres'].forEach(resizePlotCanvas);
}

function drawLinePlot(canvasId, dataPoints, color, xLabel, yLabel) {
  const canvas = document.getElementById(canvasId);
  if (!canvas || dataPoints.length < 2) return;
  resizePlotCanvas(canvasId);

  const cx     = canvas.getContext('2d');
  const width  = canvas.clientWidth;
  const height = canvas.clientHeight;
  cx.clearRect(0, 0, width, height);

  const padding = Math.max(18, Math.min(36, Math.min(width, height) * 0.07));
  const minVal  = Math.min(...dataPoints);
  const maxVal  = Math.max(...dataPoints);
  const range   = Math.max(minVal === maxVal ? 1 : maxVal - minVal, 1e-9);

  const toScreenX = i => padding + (i / (dataPoints.length - 1)) * (width  - 2 * padding);
  const toScreenY = v => height - padding - ((v - minVal) / range) * (height - 2 * padding);

  // Zero-line for signed data (e.g. velocity)
  const zeroY = toScreenY(0);
  if (zeroY > padding && zeroY < height - padding) {
    cx.strokeStyle = 'rgba(128,128,128,0.1)';
    cx.lineWidth   = 1;
    cx.beginPath();
    cx.moveTo(padding, zeroY);
    cx.lineTo(width - padding, zeroY);
    cx.stroke();
  }

  cx.fillStyle  = themeColors().muted;
  cx.font       = `${Math.max(9, width * 0.024)}px var(--font-mono, monospace)`;
  cx.globalAlpha = 0.5;
  cx.fillText(xLabel, width - padding - 7, height - padding + 12);
  cx.fillText(yLabel, padding + 2, padding + 12);
  cx.globalAlpha = 1;

  cx.beginPath();
  cx.moveTo(toScreenX(0), toScreenY(dataPoints[0]));
  for (let i = 1; i < dataPoints.length; i++) cx.lineTo(toScreenX(i), toScreenY(dataPoints[i]));
  cx.strokeStyle = color;
  cx.lineWidth   = 1.6;
  cx.stroke();
}

function drawForcePlot() {
  const canvas = document.getElementById('cForce');
  if (!canvas || forceHistory.length < 2) return;
  resizePlotCanvas('cForce');

  const cx     = canvas.getContext('2d');
  const width  = canvas.clientWidth;
  const height = canvas.clientHeight;
  cx.clearRect(0, 0, width, height);

  const padding   = Math.max(18, Math.min(36, Math.min(width, height) * 0.07));
  const buoyancyValues = forceHistory.map(f => f[0]);
  const weightValues   = forceHistory.map(f => f[1]);
  const allForces      = [...buoyancyValues, ...weightValues];
  const minForce       = Math.min(...allForces);
  const maxForce       = Math.max(...allForces);
  const forceRange     = Math.max(maxForce - minForce, 1);

  const toScreenX = i => padding + (i / (forceHistory.length - 1)) * (width  - 2 * padding);
  const toScreenY = v => height - padding - ((v - minForce) / forceRange) * (height - 2 * padding);

  cx.fillStyle  = themeColors().muted;
  cx.font       = `${Math.max(9, width * 0.024)}px var(--font-mono, monospace)`;
  cx.globalAlpha = 0.5;
  cx.fillText('t', width - padding - 7, height - padding + 12);
  cx.fillText('F(N)', padding + 2, padding + 12);
  cx.globalAlpha = 0.7;
  cx.fillText('Fb', padding + 2,  padding + 24);
  cx.fillText('Fg', padding + 28, padding + 24);
  cx.globalAlpha = 1;

  [
    [buoyancyValues, 'rgba(23,162,184,0.85)'],
    [weightValues,   'rgba(220,53,69,0.80)' ],
  ].forEach(([values, color]) => {
    cx.beginPath();
    cx.moveTo(toScreenX(0), toScreenY(values[0]));
    for (let i = 1; i < values.length; i++) cx.lineTo(toScreenX(i), toScreenY(values[i]));
    cx.strokeStyle = color;
    cx.lineWidth   = 1.5;
    cx.stroke();
  });
}

function drawPressurePlot() {
  const canvas = document.getElementById('cPres');
  if (!canvas || pressureHistory.length < 2) return;
  resizePlotCanvas('cPres');

  const cx     = canvas.getContext('2d');
  const width  = canvas.clientWidth;
  const height = canvas.clientHeight;
  cx.clearRect(0, 0, width, height);

  const padding  = Math.max(18, Math.min(36, Math.min(width, height) * 0.07));
  const maxDepth = Math.max(...pressureHistory.map(p => p[0]), 1);
  const minPres  = Math.min(...pressureHistory.map(p => p[1]));
  const maxPres  = Math.max(...pressureHistory.map(p => p[1]));
  const presRange = Math.max(maxPres - minPres, 1);

  const toScreenX = v => padding + (v / maxDepth) * (width - 2 * padding);
  const toScreenY = v => height - padding - ((v - minPres) / presRange) * (height - 2 * padding);

  cx.fillStyle  = themeColors().muted;
  cx.font       = `${Math.max(9, width * 0.024)}px var(--font-mono, monospace)`;
  cx.globalAlpha = 0.5;
  cx.fillText('depth(m)', width - padding - 40, height - padding + 12);
  cx.fillText('P(atm)',   padding + 2, padding + 12);
  cx.globalAlpha = 1;

  cx.beginPath();
  cx.moveTo(toScreenX(pressureHistory[0][0]), toScreenY(pressureHistory[0][1]));
  for (const [depth, pres] of pressureHistory) cx.lineTo(toScreenX(depth), toScreenY(pres));
  cx.strokeStyle = 'rgba(100,180,255,0.9)';
  cx.lineWidth   = 1.6;
  cx.stroke();
}

function refreshPlots() {
  // Trial: only the Depth vs Time chart is active
  drawLinePlot('cDepth', depthHistory, 'rgba(23,162,184,0.85)', 't', 'depth(m)');
}

// ── Status badge ──────────────────────────────────────────────────────────────

function updateStatusBadge(status) {
  const badge = document.getElementById('statusBadge');
  badge.className = `status-badge ${status}`;
  badge.textContent = status === 'neutral' ? 'Neutrally Buoyant'
                    : status === 'sinks'   ? 'Sinks'
                    : 'Floats';

  const hintEl        = document.getElementById('neutralHint');
  const neutralBallast = latestStaticInfo.ballastNeutral;
  if (!hintEl) return;

  if (neutralBallast === null || neutralBallast === undefined) {
    hintEl.textContent = 'Neutral buoyancy: —';
  } else if (neutralBallast > 1.0) {
    hintEl.textContent = 'Neutral buoyancy: impossible (hull too dense)';
  } else if (neutralBallast < 0) {
    hintEl.textContent = 'Neutral buoyancy: impossible (hull too light)';
  } else {
    hintEl.textContent = `Neutral buoyancy at ballast: ${(neutralBallast * 100).toFixed(1)}%`;
  }
}

// ── Controls ──────────────────────────────────────────────────────────────────

function setSimParam(key, value, displayId, formatter) {
  simParams[key] = +value;
  document.getElementById(displayId).textContent =
    typeof formatter === 'function' ? formatter(value) : value;
  hardReset();
}

function setInitialDepth(depthM) {
  startDepthM = +depthM;
  document.getElementById('vY').textContent = depthM;
}

function setYieldStrength(valueMPa) {
  simParams.yield_mpa = +valueMPa;
  document.getElementById('vYieldSlider').textContent = valueMPa;
  document.getElementById('vYield').textContent       = valueMPa;
  hardReset();
}

function applyHullMaterial(yieldKey) {
  const material = HULL_MATERIALS[yieldKey];
  if (!material) return;
  simParams.yield_mpa = +yieldKey;
  simParams.rho_hull  = material.densityKgM3;

  document.getElementById('vYield').textContent       = yieldKey;
  document.getElementById('vYieldSlider').textContent = yieldKey;
  document.getElementById('sYield').value             = yieldKey;
  document.getElementById('vHullRho').textContent     = material.densityKgM3;
  document.getElementById('sRhoH').value              = material.densityKgM3;
  document.getElementById('vRhoH').textContent        = material.densityKgM3;
  hardReset();
}

function setFlowRate(rateMs) {
  flowRateMs             = rateMs;
  streamlineNeedsRebuild = true;
  document.getElementById('vFlow').textContent = rateMs.toFixed(1);
}

function activateFlow() {
  isFlowVisible          = true;
  streamlineNeedsRebuild = true;
  document.getElementById('flowToggleLabel').textContent = 'ON';
  const rateGroup = document.getElementById('flowRateGroup');
  if (rateGroup) { rateGroup.style.opacity = '1'; rateGroup.style.pointerEvents = ''; }
}

function deactivateFlow() {
  isFlowVisible = false;
  document.getElementById('flowToggleLabel').textContent = 'OFF';
  const rateGroup = document.getElementById('flowRateGroup');
  if (rateGroup) { rateGroup.style.opacity = '0.4'; rateGroup.style.pointerEvents = 'none'; }
}

function toggleFlow(checked) {
  if (checked) {
    activateFlow();
  } else {
    deactivateFlow();
  }
}

function setNeutralDepth(depthM) {
  neutralDepthM = isNaN(depthM) || depthM < 0 ? null : depthM;
}

function clearNeutralDepth() {
  neutralDepthM = null;
  document.getElementById('nDepthInput').value = '';
}

function applyVesselPreset(presetName) {
  const preset = VESSEL_PRESETS[presetName];
  if (!preset) return;
  Object.assign(simParams, preset);

  document.getElementById('sLen').value           = preset.length;
  document.getElementById('vLen').textContent     = preset.length;
  document.getElementById('sDiam').value          = preset.diameter;
  document.getElementById('vDiam').textContent    = preset.diameter.toFixed(1);
  document.getElementById('sWall').value          = (preset.wall_thick * 100).toFixed(2);
  document.getElementById('vWall').textContent    = (preset.wall_thick * 100).toFixed(2);
  document.getElementById('sRhoH').value          = preset.rho_hull;
  document.getElementById('vRhoH').textContent    = preset.rho_hull;
  document.getElementById('sBallast').value       = preset.ballast_frac * 100;
  document.getElementById('vBallast').textContent = (preset.ballast_frac * 100).toFixed(0);
  document.getElementById('sCd').value            = preset.cd;
  document.getElementById('vCd').textContent      = preset.cd.toFixed(2);
  document.getElementById('sYield').value         = preset.yield_mpa;
  document.getElementById('vYieldSlider').textContent = preset.yield_mpa;
  document.getElementById('vYield').textContent   = preset.yield_mpa;
  hardReset();
}

function hardReset() {
  hasImploded    = false;
  implosionPhase = 'none';
  document.getElementById('implosionOverlay').classList.remove('active');

  renderDepthM   = startDepthM;
  renderVelocity = 0;
  fetchCursorY   = startDepthM;
  fetchCursorVy  = 0;

  physicsBuffer     = [];
  physicsBufferHead = 0;
  lastFrameTimestamp    = 0;
  accumulatedPhysicsMs  = 0;
  simTimeSeconds        = 0;

  depthHistory.length    = 0;
  velocityHistory.length = 0;
  forceHistory.length    = 0;
  pressureHistory.length = 0;

  tableRowBuffer.length = 0;
  tableDomRowCount      = 0;
  document.getElementById('dataTbody').innerHTML       = '';
  document.getElementById('dataRowCount').textContent  = `0 / ${MAX_TABLE_ROWS} rows (trial)`;
  document.getElementById('statusBadge').className     = 'status-badge hidden';

  latestStaticInfo       = {};
  streamlineNeedsRebuild = true;
  lastBuiltFlowRate      = -1;  // force rebuild even if flowRateMs unchanged

  drawSim();         // render immediately so canvas is never blank while fetching
  fetchPhysicsBatch();
}

function openPanel() {
  document.getElementById('sidePanel').classList.add('open');
  document.getElementById('sideBackdrop').classList.add('open');
}

function closePanel() {
  document.getElementById('sidePanel').classList.remove('open');
  document.getElementById('sideBackdrop').classList.remove('open');
}

function switchTab(tabName) {
  // Trial: Data tab blocked — redirects to upgrade
  if (tabName === 'data') { handleUpgrade(new Event('click')); return; }

  activeTabName = tabName;
  const panelMap  = { sim: 'panelSim', anal: 'panelAnal', data: 'panelData' };
  const buttonMap = { sim: 'tabSimBtn', anal: 'tabAnalBtn', data: 'tabDataBtn' };

  Object.keys(panelMap).forEach(key => {
    document.getElementById(panelMap[key]).classList.toggle('active', key === tabName);
    document.getElementById(buttonMap[key]).classList.toggle('active', key === tabName);
  });

  const settingsButton = document.getElementById('settingsBtn');
  if (settingsButton) settingsButton.style.display = tabName === 'sim' ? '' : 'none';

  if (tabName !== 'sim') closePanel();
  if (tabName === 'anal') requestAnimationFrame(() => {
    resizePlotCanvas('cDepth');
    requestAnimationFrame(refreshPlots);
  });
}

function handleUpgrade(event) {
  event.preventDefault();
  if (window.parent !== window) {
    window.parent.postMessage({ type: 'upgrade_click', sim: 'buoyancy' }, '*');
  } else {
    window.location.href = '/upgrade';
  }
}

// ── Boot ──────────────────────────────────────────────────────────────────────
requestAnimationFrame(() => {
  resizeSim();
  fetchPhysicsBatch();
  requestAnimationFrame(renderLoop);
});
</script>
</body>
</html>