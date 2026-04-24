<?php
declare(strict_types=1);
if (isset($_GET['_engine'])) {
    header('Content-Type: application/json');
    $raw  = file_get_contents('php://input');
    $post = json_decode($raw ?: '', true) ?? $_POST;
    try { echo json_encode(orbitalBatch($post, (int)($post['steps'] ?? 120))); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
$isPaid = false;

// ── Physics: Leapfrog N-body, SI units ───────────────────────────────────────
function orbitalBatch(array $p, int $steps): array {
    $G      = 6.674e-11;
    $dt     = (float)($p['dt']  ?? 86400.0);
    $simT   = (float)($p['sim_time'] ?? 0.0);
    $raw    = $p['bodies'] ?? '[]';
    $bRaw   = is_string($raw) ? (json_decode($raw, true) ?? []) : $raw;

    $n  = count($bRaw);
    $x  = []; $y  = []; $vx = []; $vy = [];
    $m  = []; $r  = []; $fixed = []; $id = [];
    foreach ($bRaw as $i => $b) {
        $x[$i]     = (float)($b['x']     ?? 0);
        $y[$i]     = (float)($b['y']     ?? 0);
        $vx[$i]    = (float)($b['vx']    ?? 0);
        $vy[$i]    = (float)($b['vy']    ?? 0);
        $m[$i]     = (float)($b['mass']  ?? 1e24);
        $r[$i]     = (float)($b['radius']?? 1e6);
        $fixed[$i] = (bool) ($b['fixed'] ?? false);
        $id[$i]    = (int)  ($b['id']    ?? $i);
    }

    $frames = [];
    for ($s = 0; $s < $steps; $s++) {
        // Leapfrog kick-drift-kick
        $ax = array_fill(0, $n, 0.0);
        $ay = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            if ($fixed[$i]) continue;
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                $dx = $x[$j] - $x[$i]; $dy = $y[$j] - $y[$i];
                $r2 = $dx*$dx + $dy*$dy + 1e10;
                $f  = $G * $m[$j] / ($r2 * sqrt($r2));
                $ax[$i] += $f * $dx; $ay[$i] += $f * $dy;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if ($fixed[$i]) continue;
            $vx[$i] += $ax[$i] * 0.5 * $dt;
            $vy[$i] += $ay[$i] * 0.5 * $dt;
            $x[$i]  += $vx[$i] * $dt;
            $y[$i]  += $vy[$i] * $dt;
        }
        $ax2 = array_fill(0, $n, 0.0);
        $ay2 = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            if ($fixed[$i]) continue;
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                $dx = $x[$j] - $x[$i]; $dy = $y[$j] - $y[$i];
                $r2 = $dx*$dx + $dy*$dy + 1e10;
                $f  = $G * $m[$j] / ($r2 * sqrt($r2));
                $ax2[$i] += $f * $dx; $ay2[$i] += $f * $dy;
            }
        }
        for ($i = 0; $i < $n; $i++) {
            if ($fixed[$i]) continue;
            $vx[$i] += $ax2[$i] * 0.5 * $dt;
            $vy[$i] += $ay2[$i] * 0.5 * $dt;
        }
        $simT += $dt;

        $snap = [];
        for ($i = 0; $i < $n; $i++) {
            $snap[] = [$id[$i], round($x[$i]), round($y[$i]),
                       round($vx[$i],2), round($vy[$i],2),
                       $m[$i], $r[$i], $fixed[$i] ? 1 : 0];
        }
        $frames[] = ['bodies' => $snap, 't' => round($simT, 1)];
    }
    return ['states' => $frames, 'finalTime' => $simT];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Orbital Mechanics · Trial</title>
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/sim-gravity-asteroid.css">
<style>
.orbit-trail { position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; }
.label-badge { position: absolute; top: 6px; left: 50%; transform: translateX(-50%);
  font-family: var(--font-mono); font-size: 0.62rem; color: var(--text-muted);
  background: var(--bg-secondary); border: 1px solid var(--border-color);
  padding: 2px 8px; border-radius: 4px; pointer-events: none; white-space: nowrap; }
.planet-label { position: absolute; font-family: var(--font-mono); font-size: 0.52rem;
  color: var(--text-muted); pointer-events: none; white-space: nowrap; }
.info-bar { display: flex; gap: 8px; flex-wrap: wrap; padding: 6px 10px;
  background: var(--bg-secondary); border-top: 1px solid var(--border-color);
  font-family: var(--font-mono); font-size: 0.6rem; color: var(--text-muted); flex-shrink: 0; }
.info-pill { display: flex; gap: 4px; align-items: center; }
.info-pill .lbl { color: var(--text-muted); }
.info-pill .val { color: var(--color-accent); font-weight: 600; }
.trial-badge { position: absolute; top: 44px; right: 8px; z-index: 20;
  font-family: var(--font-mono); font-size: 0.52rem; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--text-muted); background: var(--bg-secondary);
  border: 1px solid var(--border-color); padding: 2px 8px; border-radius: 4px; }
.speed-chip { display: inline-block; padding: 3px 8px; border-radius: 4px; cursor: pointer;
  font-family: var(--font-mono); font-size: 0.65rem; border: 1px solid var(--border-color);
  background: var(--bg-tertiary); color: var(--text-muted); transition: all 0.15s; }
.speed-chip.active { background: var(--color-accent); color: #0a0a09; border-color: var(--color-accent); font-weight: 700; }
.speed-row { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
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
  <button class="tab-btn locked-tab" id="tabAnalBtn">
    <span class="tab-icon">⌇</span><span>Mission Planner</span><span class="lock-badge">PRO</span>
  </button>
  <button class="tab-btn locked-tab" id="tabDataBtn">
    <span class="tab-icon">⊞</span><span>Perturbations</span><span class="lock-badge">PRO</span>
  </button>
</nav>

<div class="panel active" id="panelSim">
  <div class="trial-badge">Trial · Real-Time Solar System</div>
  <div class="canvas-zone pannable" id="canvasZone">
    <div class="buffer-bar-wrap"><div class="buffer-bar" id="bufferBar" style="width:0%"></div></div>
    <canvas id="simCanvas"></canvas>
    <div class="hud">
      <div class="hud-pill"><span class="lbl">t</span><span class="val" id="hudTime">0.00 yr</span></div>
      <div class="hud-pill"><span class="lbl">focus</span><span class="val" id="hudFocus">Sun</span></div>
    </div>
    <div class="event-ticker" id="eventTicker"></div>
  </div>
  <div class="ctrl-drawer" id="ctrlDrawer">
    <div class="ctrl-header" onclick="toggleDrawer()">
      <span class="ctrl-header-left">Controls</span>
      <span class="ctrl-toggle-icon" id="ctrlArrow">▲</span>
    </div>
    <div class="ctrl-body">
      <div class="slider-label" style="margin-bottom:5px"><span class="name" style="font-size:0.7rem">Time Scale</span></div>
      <div class="speed-row" id="speedRow">
        <span class="speed-chip" data-idx="0" onclick="setSpeed(0)">1 day/s</span>
        <span class="speed-chip" data-idx="1" onclick="setSpeed(1)">7 day/s</span>
        <span class="speed-chip active" data-idx="2" onclick="setSpeed(2)">30 day/s</span>
        <span class="speed-chip" data-idx="3" onclick="setSpeed(3)">1 yr/s</span>
        <span class="speed-chip" data-idx="4" onclick="setSpeed(4)">10 yr/s</span>
      </div>
      <div class="slider-label" style="margin-bottom:5px"><span class="name" style="font-size:0.7rem">Camera Focus</span></div>
      <div class="speed-row" id="focusRow">
        <span class="speed-chip active" data-focus="0" onclick="setFocus(0)">Sun</span>
        <span class="speed-chip" data-focus="1" onclick="setFocus(1)">Mercury</span>
        <span class="speed-chip" data-focus="2" onclick="setFocus(2)">Venus</span>
        <span class="speed-chip" data-focus="3" onclick="setFocus(3)">Earth</span>
        <span class="speed-chip" data-focus="4" onclick="setFocus(4)">Mars</span>
        <span class="speed-chip" data-focus="5" onclick="setFocus(5)">Jupiter</span>
      </div>
      <div class="action-row">
        <button class="act-btn" onclick="hardReset()">Reset</button>
        <button class="act-btn" onclick="toggleTrails()">Trails: <span id="trailLabel">ON</span></button>
        <button class="act-btn" onclick="toggleLabels()">Labels: <span id="labelLabel">ON</span></button>
        <button class="act-btn" onclick="togglePause()"><span id="pauseLabel">Pause</span></button>
      </div>
    </div>
  </div>
</div>

<div class="panel" id="panelAnal">
  <div class="locked-panel-inner">
    <div class="lock-overlay">
      <div class="lock-icon-big">🚀</div>
      <div class="lock-title">Mission Planning Tool</div>
      <div class="lock-sub">Advanced interplanetary trajectory analysis.</div>
      <ul class="feature-list">
        <li><span class="fi">⊕</span><div><span class="fname">Porkchop Plots</span><span class="fdesc">C3 energy maps for optimal launch windows.</span></div></li>
        <li><span class="fi">Δv</span><div><span class="fname">Gravity Assist Planner</span><span class="fdesc">Slingshot ΔV gain calculator with flyby geometry.</span></div></li>
        <li><span class="fi">~</span><div><span class="fname">Transfer Orbits</span><span class="fdesc">Hohmann & bi-elliptic with ΔV budget.</span></div></li>
      </ul>
      <a class="upgrade-btn" href="/upgrade" onclick="handleUpgrade(event)">Unlock Pro</a>
    </div>
  </div>
</div>

<div class="panel" id="panelData">
  <div class="locked-panel-inner">
    <div class="lock-overlay">
      <div class="lock-icon-big">🛰️</div>
      <div class="lock-title">Orbital Perturbations</div>
      <div class="lock-sub">Beyond point-mass gravity.</div>
      <ul class="feature-list">
        <li><span class="fi">J2</span><div><span class="fname">J2 Oblateness Effect</span><span class="fdesc">Equatorial bulge precession for satellite orbits.</span></div></li>
        <li><span class="fi">◈</span><div><span class="fname">Atmospheric Drag</span><span class="fdesc">LEO decay curves with density models.</span></div></li>
        <li><span class="fi">☉</span><div><span class="fname">Solar Radiation Pressure</span><span class="fdesc">Sail force and orbital evolution.</span></div></li>
      </ul>
      <a class="upgrade-btn" href="/upgrade" onclick="handleUpgrade(event)">Unlock Pro</a>
    </div>
  </div>
</div>

<script>
const API_PATH  = '?_engine=1';
const AU        = 1.496e11;
const YEAR_S    = 3.156e7;
const G         = 6.674e-11;
const BATCH     = 120;
const dpr       = window.devicePixelRatio || 1;

// Speed steps: dt per integration step (seconds)
const SPEEDS     = [86400, 86400*7, 86400*30, YEAR_S, YEAR_S*10];
const SPEED_LBLS = ['1 day/s','7 day/s','30 day/s','1 yr/s','10 yr/s'];
// Steps per fetch at each speed (keep ~2s of wall time per batch)
const SPEED_STEPS = [120, 120, 120, 120, 60];

// ── Real solar system data (SI) ───────────────────────────────────────────────
// [name, mass_kg, radius_m, semi_major_AU, eccentricity, color_hex, period_days]
const PLANET_DEF = [
  ['Sun',     1.989e30, 6.96e8,  0,      0,     '#FFD966', 0],
  ['Mercury', 3.301e23, 2.44e6,  0.387,  0.206, '#B0A090', 88],
  ['Venus',   4.867e24, 6.05e6,  0.723,  0.007, '#E8C87A', 225],
  ['Earth',   5.972e24, 6.37e6,  1.000,  0.017, '#4FC3F7', 365],
  ['Mars',    6.417e23, 3.39e6,  1.524,  0.093, '#EF6C4A', 687],
  ['Jupiter', 1.898e27, 7.15e7,  5.203,  0.049, '#C9A96E', 4333],
  ['Saturn',  5.683e26, 6.03e7,  9.537,  0.057, '#E4D191', 10759],
  ['Uranus',  8.681e25, 2.56e7, 19.191,  0.046, '#7FD8E8', 30687],
  ['Neptune', 1.024e26, 2.48e7, 30.069,  0.010, '#5B7FDB', 60190],
];

// TRAIL HISTORY: ring buffer of [x,y] per planet
const TRAIL_LEN = 400;
const trails = PLANET_DEF.map(() => []);

let bodies     = [];
let simTime    = 0;
let buf        = [], bufHead = 0, fetching = false;
let camX = 0, camY = 0, camScale = 1;
let speedIdx   = 2;
let focusIdx   = 0;
let showTrails = true;
let showLabels = true;
let paused     = false;
let isDragging = false, panStart = null, pinchDist0 = null;
let avgRTT = 200, rttSamples = [];
let simColors  = null;
let lastRafTs  = 0;

const simCanvas = document.getElementById('simCanvas');
const ctx       = simCanvas.getContext('2d');

function buildSimColors() {
  const s = getComputedStyle(document.documentElement);
  const v = n => s.getPropertyValue(n).trim();
  simColors = { bg: v('--bg-primary'), muted: v('--text-muted'), border: v('--border-color') };
}

function resizeSim() {
  const z = simCanvas.parentElement;
  const w = z.clientWidth, h = z.clientHeight;
  simCanvas.width = w * dpr; simCanvas.height = h * dpr;
  simCanvas.style.width = w + 'px'; simCanvas.style.height = h + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
}
window.addEventListener('resize', resizeSim);
resizeSim();

// ── Solar system initialiser ──────────────────────────────────────────────────
function makeSystem() {
  bodies = [];
  for (let i = 0; i < PLANET_DEF.length; i++) {
    const [name, mass, rad, sma, ecc, col] = PLANET_DEF[i];
    if (sma === 0) {
      bodies.push({ id: i, name, mass, radius: rad, color: col, x: 0, y: 0, vx: 0, vy: 0, fixed: true });
    } else {
      const a   = sma * AU;
      const rp  = a * (1 - ecc);          // perihelion distance
      const Msun = PLANET_DEF[0][1];
      const vc  = Math.sqrt(G * Msun * (2/rp - 1/a)); // vis-viva at perihelion
      bodies.push({ id: i, name, mass, radius: rad, color: col,
        x: rp, y: 0, vx: 0, vy: vc, fixed: false });
    }
  }
  trails.forEach(t => t.length = 0);
  simTime = 0;
}

// ── Camera ────────────────────────────────────────────────────────────────────
function centerOnFocus() {
  const b = bodies[focusIdx];
  if (!b) return;
  camX = b.x; camY = b.y;
}

function autoFit() {
  const margin = 6.0 * AU;
  const W = simCanvas.clientWidth, H = simCanvas.clientHeight;
  camScale = Math.min(W, H) / (2 * margin);
  camX = 0; camY = 0;
}

function worldToScreen(wx, wy) {
  const W = simCanvas.clientWidth, H = simCanvas.clientHeight;
  return [W/2 + (wx - camX)*camScale, H/2 - (wy - camY)*camScale];
}

// ── Draw ──────────────────────────────────────────────────────────────────────
function drawSim() {
  if (!simColors) buildSimColors();
  const W = simCanvas.clientWidth, H = simCanvas.clientHeight;
  ctx.clearRect(0, 0, W, H);
  ctx.fillStyle = simColors.bg;
  ctx.fillRect(0, 0, W, H);

  // Dot grid
  ctx.fillStyle = 'rgba(128,128,128,0.06)';
  const gs = 40;
  for (let gx = (W/2)%gs; gx < W; gx += gs)
    for (let gy = (H/2)%gs; gy < H; gy += gs) {
      ctx.beginPath(); ctx.arc(gx, gy, 0.7, 0, Math.PI*2); ctx.fill();
    }

  // Trails
  if (showTrails) {
    for (let i = 1; i < bodies.length; i++) {
      const trail = trails[i];
      if (trail.length < 2) continue;
      ctx.beginPath();
      const [sx0, sy0] = worldToScreen(trail[0][0], trail[0][1]);
      ctx.moveTo(sx0, sy0);
      for (let j = 1; j < trail.length; j++) {
        const [sx, sy] = worldToScreen(trail[j][0], trail[j][1]);
        ctx.lineTo(sx, sy);
      }
      const alpha = 0.35;
      ctx.strokeStyle = bodies[i].color + Math.round(alpha * 255).toString(16).padStart(2,'0');
      ctx.lineWidth = 0.8; ctx.stroke();
    }
  }

  // Bodies
  for (const b of bodies) {
    const [sx, sy] = worldToScreen(b.x, b.y);
    const sr = b.fixed
      ? Math.max(6, b.radius * camScale * 0.0002)
      : Math.max(2.5, b.radius * camScale * 0.00015);

    if (b.fixed) {
      // Glow
      for (let g = 4; g >= 1; g--) {
        ctx.beginPath(); ctx.arc(sx, sy, sr*(1+g*0.5), 0, Math.PI*2);
        ctx.fillStyle = b.color + Math.round(0.04/g*255).toString(16).padStart(2,'0');
        ctx.fill();
      }
    }
    const grad = ctx.createRadialGradient(sx - sr*0.3, sy - sr*0.3, 0, sx, sy, sr);
    grad.addColorStop(0, b.color); grad.addColorStop(1, b.color + '88');
    ctx.beginPath(); ctx.arc(sx, sy, sr, 0, Math.PI*2);
    ctx.fillStyle = grad; ctx.fill();

    if (showLabels) {
      ctx.fillStyle = simColors.muted;
      ctx.font = `${Math.max(9, Math.min(11, W*0.022))}px var(--font-mono, monospace)`;
      ctx.globalAlpha = 0.65;
      ctx.fillText(b.name, sx + sr + 3, sy - 2);
      ctx.globalAlpha = 1;
    }
  }
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
async function fetchBatch() {
  if (fetching || !bodies.length || paused) return;
  fetching = true;
  const t0 = performance.now();
  try {
    const payload = new URLSearchParams({
      action: 'batch', type: 'orbital',
      steps: SPEED_STEPS[speedIdx],
      dt: SPEEDS[speedIdx],
      bodies: JSON.stringify(bodies.map(b => ({
        id:b.id, x:b.x, y:b.y, vx:b.vx, vy:b.vy,
        mass:b.mass, radius:b.radius, fixed:b.fixed
      }))),
      sim_time: simTime,
    });
    const res  = await fetch(API_PATH, { method:'POST', body: payload });
    const json = await res.json();
    const rtt  = performance.now() - t0;
    rttSamples.push(rtt); if (rttSamples.length > 8) rttSamples.shift();
    avgRTT = rttSamples.reduce((a,b) => a+b, 0) / rttSamples.length;
    if (json.states && json.states.length) {
      if (bufHead > 30) { buf.splice(0, bufHead); bufHead = 0; }
      buf.push(...json.states);
    }
    if (json.finalTime) simTime = json.finalTime;
  } catch(e) { clientFallback(); }
  finally { fetching = false; }
}

function clientFallback() {
  const dt = SPEEDS[speedIdx], Gv = G;
  const bs = bodies.map(b => ({...b}));
  const n = bs.length;
  for (let s = 0; s < SPEED_STEPS[speedIdx]; s++) {
    const ax = new Array(n).fill(0), ay = new Array(n).fill(0);
    for (let i = 0; i < n; i++) {
      if (bs[i].fixed) continue;
      for (let j = 0; j < n; j++) {
        if (i===j) continue;
        const dx=bs[j].x-bs[i].x, dy=bs[j].y-bs[i].y;
        const r2=dx*dx+dy*dy+1e10, r=Math.sqrt(r2);
        const f=Gv*bs[j].mass/(r2*r);
        ax[i]+=f*dx; ay[i]+=f*dy;
      }
    }
    for (let i = 0; i < n; i++) {
      if (bs[i].fixed) continue;
      bs[i].vx+=ax[i]*dt; bs[i].vy+=ay[i]*dt;
      bs[i].x+=bs[i].vx*dt; bs[i].y+=bs[i].vy*dt;
    }
    simTime += dt;
    buf.push({ bodies: bs.map(b=>[b.id,b.x,b.y,b.vx,b.vy,b.mass,b.radius,b.fixed?1:0]), t: simTime });
  }
}

// ── Render loop ───────────────────────────────────────────────────────────────
function renderLoop(ts) {
  requestAnimationFrame(renderLoop);
  const avail = buf.length - bufHead;
  const pct   = Math.min(1, avail / SPEED_STEPS[speedIdx]);
  const bar   = document.getElementById('bufferBar');
  bar.style.width      = (pct*100) + '%';
  bar.style.background = pct < 0.15 ? 'var(--color-danger)' : pct < 0.45 ? 'var(--color-accent)' : 'var(--color-success)';

  if (!fetching && !paused && pct < 0.5) fetchBatch();
  if (paused || bufHead >= buf.length) { lastRafTs = ts; return; }

  const wallDt = lastRafTs === 0 ? 16.7 : Math.min(ts - lastRafTs, 100);
  const adv    = Math.max(1, Math.min(3, Math.round(wallDt / 16.7)));
  lastRafTs = ts;

  let frame;
  for (let f = 0; f < adv; f++) {
    if (bufHead >= buf.length) break;
    frame = buf[bufHead++];
  }
  if (!frame) return;

  // Update body positions from frame
  const bmap = {};
  for (const b of bodies) bmap[b.id] = b;
  for (const fb of frame.bodies) {
    const [fid,fx,fy,fvx,fvy] = fb;
    if (bmap[fid]) { bmap[fid].x=fx; bmap[fid].y=fy; bmap[fid].vx=fvx; bmap[fid].vy=fvy; }
  }

  // Append trails
  for (let i = 1; i < bodies.length; i++) {
    trails[i].push([bodies[i].x, bodies[i].y]);
    if (trails[i].length > TRAIL_LEN) trails[i].shift();
  }

  simTime = frame.t;
  document.getElementById('hudTime').textContent = (simTime/YEAR_S).toFixed(2) + ' yr';

  // Follow focus
  if (focusIdx > 0 && bodies[focusIdx]) {
    camX = bodies[focusIdx].x;
    camY = bodies[focusIdx].y;
  }
  document.getElementById('hudFocus').textContent = bodies[focusIdx]?.name || 'Sun';

  drawSim();
}

// ── Controls ──────────────────────────────────────────────────────────────────
function setSpeed(idx) {
  speedIdx = idx;
  document.querySelectorAll('.speed-chip').forEach(c =>
    c.classList.toggle('active', +c.dataset.idx === idx));
  buf = []; bufHead = 0; fetchBatch();
}

function setFocus(idx) {
  focusIdx = idx;
  document.querySelectorAll('[data-focus]').forEach(c =>
    c.classList.toggle('active', +c.dataset.focus === idx));
  if (idx === 0) { camX = 0; camY = 0; autoFit(); }
}

let trailsOn = true, labelsOn = true;
function toggleTrails() { showTrails = !showTrails; document.getElementById('trailLabel').textContent = showTrails?'ON':'OFF'; }
function toggleLabels() { showLabels = !showLabels; document.getElementById('labelLabel').textContent = showLabels?'ON':'OFF'; }
function togglePause()  { paused = !paused; document.getElementById('pauseLabel').textContent = paused?'Resume':'Pause'; }

function hardReset() { makeSystem(); autoFit(); buf=[]; bufHead=0; simTime=0; fetchBatch(); }
function toggleDrawer() {
  const d = document.getElementById('ctrlDrawer'); d.classList.toggle('open');
  document.getElementById('ctrlArrow').textContent = d.classList.contains('open')?'▼':'▲';
}

// ── Pan / zoom ────────────────────────────────────────────────────────────────
const cz = document.getElementById('canvasZone');
function getTouches(e) {
  return Array.from(e.touches).map(t => {
    const r = simCanvas.getBoundingClientRect();
    return { x: t.clientX-r.left, y: t.clientY-r.top };
  });
}
cz.addEventListener('touchstart', e => {
  e.preventDefault();
  const ts = getTouches(e);
  if (ts.length===1) { panStart={sx:ts[0].x,sy:ts[0].y,cx:camX,cy:camY}; isDragging=true; cz.classList.add('panning'); }
  else if (ts.length===2) { const dx=ts[0].x-ts[1].x,dy=ts[0].y-ts[1].y; pinchDist0=Math.sqrt(dx*dx+dy*dy); isDragging=false; }
}, {passive:false});
cz.addEventListener('touchmove', e => {
  e.preventDefault();
  const ts = getTouches(e);
  if (ts.length===1&&isDragging&&panStart) { camX=panStart.cx-(ts[0].x-panStart.sx)/camScale; camY=panStart.cy+(ts[0].y-panStart.sy)/camScale; drawSim(); }
  else if (ts.length===2&&pinchDist0!==null) { const dx=ts[0].x-ts[1].x,dy=ts[0].y-ts[1].y; const d=Math.sqrt(dx*dx+dy*dy); camScale=Math.max(1e-13,Math.min(1e-8,camScale*d/pinchDist0)); pinchDist0=d; drawSim(); }
}, {passive:false});
cz.addEventListener('touchend', e => { e.preventDefault(); if(e.touches.length<2)pinchDist0=null; if(e.touches.length===0){isDragging=false;panStart=null;cz.classList.remove('panning');} }, {passive:false});
cz.addEventListener('mousedown', e => { panStart={sx:e.clientX,sy:e.clientY,cx:camX,cy:camY}; isDragging=true; cz.classList.add('panning'); });
cz.addEventListener('mousemove', e => { if(!isDragging||!panStart)return; camX=panStart.cx-(e.clientX-panStart.sx)/camScale; camY=panStart.cy+(e.clientY-panStart.sy)/camScale; drawSim(); });
cz.addEventListener('mouseup',    () => { isDragging=false; panStart=null; cz.classList.remove('panning'); });
cz.addEventListener('mouseleave', () => { isDragging=false; panStart=null; cz.classList.remove('panning'); });
cz.addEventListener('wheel', e => { e.preventDefault(); camScale=Math.max(1e-13,Math.min(1e-8,camScale*(e.deltaY<0?1.12:0.89))); drawSim(); }, {passive:false});

// ── Tab switch ────────────────────────────────────────────────────────────────
function switchTab(n) {
  ['sim','anal','data'].forEach(k => {
    document.getElementById('panel'+k.charAt(0).toUpperCase()+k.slice(1)).classList.toggle('active', k===n);
    document.getElementById('tab'+k.charAt(0).toUpperCase()+k.slice(1)+'Btn').classList.toggle('active', k===n);
  });
}
function handleUpgrade(e) {
  e.preventDefault();
  if (window.parent!==window) window.parent.postMessage({type:'upgrade_click',sim:'orbital'},'*');
  else window.location.href='/upgrade';
}

// ── Boot ──────────────────────────────────────────────────────────────────────
(function() {
  const d=document.getElementById('ctrlDrawer'), a=document.getElementById('ctrlArrow');
  if (window.innerWidth >= 768) { d.classList.add('open'); a.textContent='▼'; }
})();
requestAnimationFrame(() => {
  resizeSim(); makeSystem(); autoFit(); fetchBatch(); requestAnimationFrame(renderLoop);
});
</script>
</body>
</html>