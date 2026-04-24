<?php
declare(strict_types=1);
if (isset($_GET['_engine'])) {
    header('Content-Type: application/json');
    $raw  = file_get_contents('php://input');
    $post = json_decode($raw ?: '', true) ?? $_POST;
    try { echo json_encode(projectileBatch($post, (int)($post['steps'] ?? 120))); }
    catch (Throwable $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
    exit;
}
$isPaid = false;

// ── Physics ───────────────────────────────────────────────────────────────────
function projectileBatch(array $p, int $steps): array {
    $g      = (float)($p['gravity']  ?? 9.81);
    $v0     = (float)($p['v0']       ?? 100.0);
    $angle  = (float)($p['angle']    ?? 45.0);
    $mass   = (float)($p['mass']     ?? 1.0);
    $cd     = (float)($p['cd']       ?? 0.47);
    $area   = (float)($p['area']     ?? 0.01);
    $dt     = (float)($p['dt']       ?? 0.05);
    $x      = (float)($p['x']        ?? 0.0);
    $y      = (float)($p['y']        ?? 0.0);
    $vx     = (float)($p['vx']       ?? $v0 * cos(deg2rad($angle)));
    $vy     = (float)($p['vy']       ?? $v0 * sin(deg2rad($angle)));
    $rho0   = 1.225; // sea-level kg/m3

    $states = [];
    for ($i = 0; $i < $steps; $i++) {
        // Vacuum only (trial): no drag
        $ax = 0.0;
        $ay = -$g;

        $vx += $ax * $dt;
        $vy += $ay * $dt;
        $x  += $vx * $dt;
        $y  += $vy * $dt;

        if ($y < 0) { $y = 0; $vy = 0; $vx = 0; }

        $speed = sqrt($vx*$vx + $vy*$vy);
        $ke    = 0.5 * $mass * $speed * $speed;
        $pe    = $mass * $g * max(0, $y);

        $states[] = [
            round($x, 4),
            round($y, 4),
            round($vx, 5),
            round($vy, 5),
            round($speed, 4),
            round($ke, 4),
            round($pe, 4),
        ];

        if ($y <= 0 && $i > 0) break;
    }

    return [
        'states'    => $states,
        'finalX'    => $x, 'finalY' => $y,
        'finalVx'   => $vx, 'finalVy' => $vy,
        'landed'    => $y <= 0,
    ];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Projectile · Trial</title>
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/sim-gravity-asteroid.css">
<style>
.trial-badge { position: absolute; top: 44px; right: 8px; z-index: 20;
  font-family: var(--font-mono); font-size: 0.52rem; letter-spacing: 0.08em;
  text-transform: uppercase; color: var(--text-muted); background: var(--bg-secondary);
  border: 1px solid var(--border-color); padding: 2px 8px; border-radius: 4px; }
.range-badge { position: absolute; bottom: 14px; left: 50%; transform: translateX(-50%);
  font-family: var(--font-mono); font-size: 0.65rem; color: var(--color-accent);
  background: var(--bg-secondary); border: 1px solid var(--border-color);
  padding: 2px 10px; border-radius: 4px; pointer-events: none; white-space: nowrap; }
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
    <span class="tab-icon">⊞</span><span>Rocketry</span><span class="lock-badge">PRO</span>
  </button>
</nav>

<div class="panel active" id="panelSim">
  <div class="trial-badge">Trial · Vacuum</div>
  <div class="canvas-zone" id="canvasZone">
    <div class="buffer-bar-wrap"><div class="buffer-bar" id="bufferBar" style="width:0%"></div></div>
    <canvas id="simCanvas"></canvas>
    <div class="range-badge" id="rangeBadge">—</div>
    <div class="hud">
      <div class="hud-pill"><span class="lbl">x</span><span class="val" id="hudX">0.00</span></div>
      <div class="hud-pill"><span class="lbl">y</span><span class="val" id="hudY">0.00 m</span></div>
      <div class="hud-pill"><span class="lbl">|v|</span><span class="val" id="hudSpd">—</span></div>
      <div class="hud-pill"><span class="lbl">KE</span><span class="val" id="hudKE">—</span></div>
    </div>
  </div>
  <div class="ctrl-drawer" id="ctrlDrawer">
    <div class="ctrl-header" onclick="toggleDrawer()">
      <span class="ctrl-header-left">Launch Parameters</span>
      <span class="ctrl-toggle-icon" id="ctrlArrow">▲</span>
    </div>
    <div class="ctrl-body">
      <div class="slider-grid">
        <div class="slider-group">
          <div class="slider-label"><span class="name">Launch Speed (m/s)</span><span class="val" id="vV0">100</span></div>
          <input type="range" id="sV0" min="10" max="2000" step="10" value="100" oninput="setP('v0',+this.value,'vV0',v=>v.toFixed(0))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Angle (°)</span><span class="val" id="vAngle">45°</span></div>
          <input type="range" id="sAngle" min="1" max="89" step="1" value="45" oninput="setP('angle',+this.value,'vAngle',v=>v+'°')">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Mass (kg)</span><span class="val" id="vMass">1.0</span></div>
          <input type="range" id="sMass" min="0.1" max="100" step="0.1" value="1" oninput="setP('mass',+this.value,'vMass',v=>(+v).toFixed(1))">
        </div>
        <div class="slider-group">
          <div class="slider-label"><span class="name">Gravity (m/s²)</span><span class="val" id="vG">9.81</span></div>
          <input type="range" id="sG" min="0.1" max="25" step="0.01" value="9.81" oninput="setP('gravity',+this.value,'vG',v=>(+v).toFixed(2))">
        </div>
      </div>
      <div class="action-row">
        <button class="act-btn" onclick="launch()">Launch</button>
        <button class="act-btn" onclick="hardReset()">Reset</button>
        <button class="act-btn" onclick="applyPreset('bullet')">Bullet</button>
        <button class="act-btn" onclick="applyPreset('arrow')">Arrow</button>
        <button class="act-btn" onclick="applyPreset('moon')">Moon</button>
        <button class="act-btn" onclick="applyPreset('mars')">Mars</button>
      </div>
    </div>
  </div>
</div>

<div class="panel" id="panelAnal">
  <div class="analysis-grid">
    <div class="plot-cell"><div class="plot-label">Trajectory — x vs y (m)</div><canvas id="cTraj"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Height vs Time (m)</div><canvas id="cHeight"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Speed vs Time (m/s)</div><canvas id="cSpeed"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Energy vs Time (J)</div><canvas id="cEnergy"></canvas></div>
  </div>
</div>

<div class="panel" id="panelData">
  <div class="locked-panel-inner">
    <div class="lock-overlay">
      <div class="lock-icon-big">🚀</div>
      <div class="lock-title">Atmospheric Rocketry</div>
      <div class="lock-sub">Real-world trajectory modelling beyond vacuum physics.</div>
      <ul class="feature-list">
        <li><span class="fi">~</span><div><span class="fname">Standard Atmosphere</span><span class="fdesc">Variable air density drag model by altitude.</span></div></li>
        <li><span class="fi">⊕</span><div><span class="fname">Coriolis Effect</span><span class="fdesc">Long-range trajectory deflection by latitude.</span></div></li>
        <li><span class="fi">Isp</span><div><span class="fname">Tsiolkovsky Rocket Builder</span><span class="fdesc">Balance Isp and fuel mass — tyranny of the rocket equation.</span></div></li>
        <li><span class="fi">◈</span><div><span class="fname">Multi-stage Design</span><span class="fdesc">Stage separation with live ΔV budget display.</span></div></li>
      </ul>
      <a class="upgrade-btn" href="/upgrade" onclick="handleUpgrade(event)">Unlock Pro</a>
    </div>
  </div>
</div>

<script>
const API_PATH  = '?_engine=1';
const BATCH     = 120;
const dpr       = window.devicePixelRatio || 1;

const params = { gravity: 9.81, v0: 100, angle: 45, mass: 1.0, cd: 0.47, area: 0.01 };
const PRESETS = {
  bullet: { v0: 900,  angle: 45, mass: 0.01, gravity: 9.81 },
  arrow:  { v0: 60,   angle: 45, mass: 0.03, gravity: 9.81 },
  moon:   { v0: 100,  angle: 45, mass: 1.0,  gravity: 1.62 },
  mars:   { v0: 100,  angle: 45, mass: 1.0,  gravity: 3.72 },
};

// Full trajectory storage
let trajectory = [];  // [{x,y,vx,vy,spd,ke,pe}]
let head       = 0;   // current playback frame
let fetching   = false, landed = false;
let lastMaxX   = 1, lastMaxY = 1;
let activeTab  = 'sim';
let simColors  = null;

const simCanvas = document.getElementById('simCanvas');
const ctx       = simCanvas.getContext('2d');

function buildSimColors() {
  const s = getComputedStyle(document.documentElement);
  const v = n => s.getPropertyValue(n).trim();
  simColors = { bg: v('--bg-primary'), muted: v('--text-muted'), accent: v('--color-accent'),
    success: v('--color-success'), danger: v('--color-danger'), border: v('--border-color') };
}

function resizeSim() {
  const z = simCanvas.parentElement;
  const w = z.clientWidth, h = z.clientHeight;
  simCanvas.width = w*dpr; simCanvas.height = h*dpr;
  simCanvas.style.width = w+'px'; simCanvas.style.height = h+'px';
  ctx.setTransform(dpr,0,0,dpr,0,0);
}
window.addEventListener('resize', () => { resizeSim(); if(activeTab==='anal') resizePlots(); });
resizeSim();

// ── Fetch full trajectory in one call ─────────────────────────────────────────
async function fetchFull() {
  if (fetching) return; fetching = true;
  const angleRad = params.angle * Math.PI / 180;
  trajectory = []; head = 0; landed = false;
  document.getElementById('rangeBadge').textContent = '—';

  // Keep fetching batches until landed
  let x=0, y=0, vx=params.v0*Math.cos(angleRad), vy=params.v0*Math.sin(angleRad);
  let batchLanded = false;
  while (!batchLanded) {
    try {
      const body = new URLSearchParams({
        action:'batch', type:'projectile', steps:BATCH, dt:0.05,
        x, y, vx, vy,
        gravity: params.gravity, mass: params.mass,
        cd: params.cd, area: params.area,
      });
      const res  = await fetch(API_PATH, { method:'POST', body });
      const json = await res.json();
      if (!json.states || !json.states.length) break;
      for (const s of json.states) {
        trajectory.push({ x:s[0], y:s[1], vx:s[2], vy:s[3], spd:s[4], ke:s[5], pe:s[6] });
      }
      x = json.finalX; y = json.finalY; vx = json.finalVx; vy = json.finalVy;
      batchLanded = json.landed;
      if (trajectory.length > 20000) break; // safety cap
    } catch(e) { clientFallback(); break; }
  }
  landed = true;
  fetching = false;
  lastMaxX = trajectory.reduce((m,p2) => Math.max(m,p2.x), 1);
  lastMaxY = trajectory.reduce((m,p2) => Math.max(m,p2.y), 1);
  const range = trajectory.length ? trajectory[trajectory.length-1].x.toFixed(1) : '—';
  document.getElementById('rangeBadge').textContent = 'Range: ' + range + ' m';
  document.getElementById('bufferBar').style.width = '100%';
  document.getElementById('bufferBar').style.background = 'var(--color-success)';
  startPlayback();
}

function clientFallback() {
  const g=params.gravity, vx0=params.v0*Math.cos(params.angle*Math.PI/180), vy0=params.v0*Math.sin(params.angle*Math.PI/180);
  let x=0,y=0,vx=vx0,vy=vy0,dt=0.05;
  while(y>=0||trajectory.length===0){
    const spd=Math.sqrt(vx*vx+vy*vy);
    trajectory.push({x,y:Math.max(0,y),vx,vy,spd,ke:0.5*params.mass*spd*spd,pe:params.mass*g*Math.max(0,y)});
    vx+=0*dt; vy+=-g*dt; x+=vx*dt; y+=vy*dt;
    if(y<0&&trajectory.length>1)break;
    if(trajectory.length>20000)break;
  }
  landed=true;
  lastMaxX=trajectory.reduce((m,p2)=>Math.max(m,p2.x),1);
  lastMaxY=trajectory.reduce((m,p2)=>Math.max(m,p2.y),1);
}

// ── Playback ──────────────────────────────────────────────────────────────────
function startPlayback() { head = 0; requestAnimationFrame(playFrame); }

function playFrame() {
  if (head >= trajectory.length) { head = trajectory.length - 1; drawFrame(head); return; }
  drawFrame(head);
  head = Math.min(head + Math.max(1, Math.floor(trajectory.length / 200)), trajectory.length);
  if (head < trajectory.length) requestAnimationFrame(playFrame);
}

function drawFrame(i) {
  if (!simColors) buildSimColors();
  const fr = trajectory[i];
  if (!fr) return;
  const W = simCanvas.clientWidth, H = simCanvas.clientHeight;
  ctx.clearRect(0, 0, W, H);

  const pad = 40;
  const groundY = H - pad;
  const scaleX = (W - 2*pad) / Math.max(lastMaxX, 1);
  const scaleY = (H - 2*pad) / Math.max(lastMaxY * 1.1, 1);
  const wx = x => pad + x * scaleX;
  const wy = y => groundY - y * scaleY;

  // Background
  ctx.fillStyle = simColors.bg; ctx.fillRect(0,0,W,H);

  // Dot grid
  ctx.fillStyle = 'rgba(128,128,128,0.06)';
  for (let gx=40;gx<W;gx+=40) for (let gy=40;gy<H;gy+=40) { ctx.beginPath();ctx.arc(gx,gy,0.7,0,Math.PI*2);ctx.fill(); }

  // Ground
  ctx.strokeStyle = 'rgba(128,128,128,0.3)'; ctx.lineWidth = 1;
  ctx.beginPath(); ctx.moveTo(pad, groundY); ctx.lineTo(W-pad, groundY); ctx.stroke();

  // Trail
  const trailEnd = Math.min(i+1, trajectory.length);
  const trailStart = Math.max(0, trailEnd - 400);
  if (trailEnd - trailStart > 1) {
    ctx.beginPath();
    ctx.moveTo(wx(trajectory[trailStart].x), wy(trajectory[trailStart].y));
    for (let j = trailStart+1; j < trailEnd; j++) {
      ctx.lineTo(wx(trajectory[j].x), wy(trajectory[j].y));
    }
    ctx.strokeStyle = 'rgba(100,180,255,0.4)'; ctx.lineWidth = 1.5; ctx.stroke();
  }

  // Projectile
  const bx = wx(fr.x), by = wy(fr.y);
  ctx.beginPath(); ctx.arc(bx, by, 6, 0, Math.PI*2);
  ctx.fillStyle = '#4fc3f7'; ctx.fill();
  ctx.strokeStyle = '#80d8ff'; ctx.lineWidth = 1.5; ctx.stroke();

  // Velocity vector
  const vscale = 0.12;
  ctx.strokeStyle = 'rgba(255,193,7,0.7)'; ctx.lineWidth = 1.5;
  ctx.beginPath(); ctx.moveTo(bx, by);
  ctx.lineTo(bx + fr.vx*vscale, by - fr.vy*vscale); ctx.stroke();

  // HUD
  document.getElementById('hudX').textContent   = fr.x.toFixed(1) + ' m';
  document.getElementById('hudY').textContent   = fr.y.toFixed(1) + ' m';
  document.getElementById('hudSpd').textContent = fr.spd.toFixed(1) + ' m/s';
  document.getElementById('hudKE').textContent  = fr.ke.toFixed(1) + ' J';

  if (activeTab === 'anal') refreshPlots(i);
}

// ── Plots ─────────────────────────────────────────────────────────────────────
function resizePlot(id) {
  const c = document.getElementById(id); if (!c) return;
  const cell = c.parentElement;
  const w=cell.clientWidth, h=cell.clientHeight;
  if(w<2||h<2) return;
  c.width=w*dpr; c.height=h*dpr;
  c.style.width=w+'px'; c.style.height=h+'px';
  c.getContext('2d').setTransform(dpr,0,0,dpr,0,0);
}
function resizePlots() { ['cTraj','cHeight','cSpeed','cEnergy'].forEach(resizePlot); }

function drawSimpleLine(canvasId, xArr, yArr, color, xl, yl, curI) {
  resizePlot(canvasId);
  const c = document.getElementById(canvasId); if(!c||yArr.length<2) return;
  const cx2=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx2.clearRect(0,0,W,H);
  if (!simColors) buildSimColors();
  const p=Math.max(18,Math.min(36,Math.min(W,H)*0.07));
  let mnX=xArr[0],mxX=xArr[0],mnY=yArr[0],mxY=yArr[0];
  for(let i=0;i<xArr.length;i++){if(xArr[i]<mnX)mnX=xArr[i];if(xArr[i]>mxX)mxX=xArr[i];if(yArr[i]<mnY)mnY=yArr[i];if(yArr[i]>mxY)mxY=yArr[i];}
  const rx=Math.max(mxX-mnX,1),ry=Math.max(mxY-mnY,1);
  const sx=v=>p+(v-mnX)/rx*(W-2*p);
  const sy=v=>H-p-(v-mnY)/ry*(H-2*p);
  const y0=sy(0); if(y0>p&&y0<H-p){cx2.strokeStyle='rgba(128,128,128,0.15)';cx2.lineWidth=1;cx2.beginPath();cx2.moveTo(p,y0);cx2.lineTo(W-p,y0);cx2.stroke();}
  cx2.fillStyle=simColors.muted; cx2.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx2.globalAlpha=0.5; cx2.fillText(xl,W-p-7,H-p+12); cx2.fillText(yl,p+2,p+12); cx2.globalAlpha=1;
  cx2.beginPath(); cx2.moveTo(sx(xArr[0]),sy(yArr[0]));
  for(let i=1;i<xArr.length;i++) cx2.lineTo(sx(xArr[i]),sy(yArr[i]));
  cx2.strokeStyle=color; cx2.lineWidth=1.6; cx2.stroke();
  if(curI<xArr.length){cx2.beginPath();cx2.arc(sx(xArr[curI]),sy(yArr[curI]),3.5,0,Math.PI*2);cx2.fillStyle=simColors.accent;cx2.fill();}
}

function refreshPlots(curI) {
  if (!trajectory.length) return;
  const n = trajectory.length;
  const xs   = trajectory.map(p2=>p2.x);
  const ys   = trajectory.map(p2=>p2.y);
  const ts   = trajectory.map((_,i)=>i*0.05);
  const spds = trajectory.map(p2=>p2.spd);
  const kes  = trajectory.map(p2=>p2.ke);
  const pes  = trajectory.map(p2=>p2.pe);
  const tes  = trajectory.map(p2=>p2.ke+p2.pe);
  drawSimpleLine('cTraj',   xs,   ys,   'rgba(79,195,247,0.85)', 'x(m)', 'y(m)',   curI);
  drawSimpleLine('cHeight', ts,   ys,   'rgba(100,220,120,0.85)','t(s)', 'y(m)',   curI);
  drawSimpleLine('cSpeed',  ts,   spds, 'rgba(240,192,64,0.85)', 't(s)', 'v(m/s)', curI);
  // Energy overlay
  resizePlot('cEnergy');
  const c=document.getElementById('cEnergy'); if(!c||kes.length<2) return;
  const cx2=c.getContext('2d');
  const W=c.clientWidth, H=c.clientHeight;
  cx2.clearRect(0,0,W,H);
  if(!simColors)buildSimColors();
  const p=Math.max(18,Math.min(36,Math.min(W,H)*0.07));
  const all=[...kes,...pes,...tes]; let mn=all[0],mx=all[0];
  for(const v of all){if(v<mn)mn=v;if(v>mx)mx=v;}
  const rx=Math.max(ts[ts.length-1],1),ry=Math.max(mx-mn,1);
  const sx2=v=>p+v/rx*(W-2*p), sy2=v=>H-p-(v-mn)/ry*(H-2*p);
  cx2.fillStyle=simColors.muted; cx2.font=`${Math.max(9,W*0.024)}px var(--font-mono,monospace)`;
  cx2.globalAlpha=0.5; cx2.fillText('t(s)',W-p-7,H-p+12); cx2.fillText('E(J)',p+2,p+12); cx2.globalAlpha=0.65;
  cx2.fillText('KE',p+2,p+26); cx2.fillText('PE',p+22,p+26); cx2.fillText('TE',p+42,p+26); cx2.globalAlpha=1;
  [[kes,'rgba(240,192,64,0.8)'],[pes,'rgba(23,162,184,0.8)'],[tes,'rgba(40,167,69,0.8)']].forEach(([arr,col])=>{
    cx2.beginPath(); cx2.moveTo(sx2(ts[0]),sy2(arr[0]));
    for(let i=1;i<arr.length;i++) cx2.lineTo(sx2(ts[i]),sy2(arr[i]));
    cx2.strokeStyle=col; cx2.lineWidth=1.5; cx2.stroke();
  });
}

// ── Controls ──────────────────────────────────────────────────────────────────
function setP(k, v, id, fmt) {
  params[k] = +v;
  document.getElementById(id).textContent = fmt(v);
  hardReset();
}

function applyPreset(name) {
  const P = PRESETS[name]; if (!P) return;
  Object.assign(params, P);
  document.getElementById('sV0').value    = params.v0;    document.getElementById('vV0').textContent    = params.v0;
  document.getElementById('sAngle').value = params.angle; document.getElementById('vAngle').textContent = params.angle+'°';
  document.getElementById('sMass').value  = params.mass;  document.getElementById('vMass').textContent  = params.mass.toFixed(1);
  document.getElementById('sG').value     = params.gravity; document.getElementById('vG').textContent   = params.gravity.toFixed(2);
  hardReset();
}

function launch()     { hardReset(); }
function hardReset()  { trajectory=[]; head=0; landed=false; fetchFull(); }
function toggleDrawer() {
  const d=document.getElementById('ctrlDrawer'); d.classList.toggle('open');
  document.getElementById('ctrlArrow').textContent=d.classList.contains('open')?'▼':'▲';
}
function switchTab(n) {
  activeTab = n;
  ['sim','anal','data'].forEach(k=>{
    document.getElementById('panel'+k.charAt(0).toUpperCase()+k.slice(1)).classList.toggle('active',k===n);
    document.getElementById('tab'+k.charAt(0).toUpperCase()+k.slice(1)+'Btn').classList.toggle('active',k===n);
  });
  if(n==='anal') requestAnimationFrame(()=>{ resizePlots(); requestAnimationFrame(()=>refreshPlots(head)); });
}
function handleUpgrade(e) {
  e.preventDefault();
  if(window.parent!==window) window.parent.postMessage({type:'upgrade_click',sim:'projectile'},'*');
  else window.location.href='/upgrade';
}

(function(){
  const d=document.getElementById('ctrlDrawer'),a=document.getElementById('ctrlArrow');
  if(window.innerWidth>=768){d.classList.add('open');a.textContent='▼';}
})();
requestAnimationFrame(()=>{ resizeSim(); fetchFull(); });
</script>
</body>
</html>