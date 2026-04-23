<?php
declare(strict_types=1);
// ── Engine endpoint ──────────────────────────────────────────────────────────
if (isset($_GET['_engine'])) {
    header('Content-Type: application/json');
    $raw  = file_get_contents('php://input');
    $post = json_decode($raw ?: '', true) ?? $_POST;
    try {
        $steps = (int)($post['steps'] ?? 60);
        echo json_encode(batchGravityAsteroid($post, $steps));
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ── Access check ─────────────────────────────────────────────────────────────
$isPaid = false;  // trial version always serves trial UI

// ── Physics engine functions ─────────────────────────────────────────────────
private static function batchGravityAsteroid(array $params, int $steps): array {
    // ── Decode input ─────────────────────────────────────────────────────
    $bodiesRaw = $params['bodies'] ?? '[]';
    if (is_string($bodiesRaw)) {
        $bodiesRaw = json_decode($bodiesRaw, true) ?? [];
    }
    $G             = (float)($params['G']              ?? 6.674e-11);
    $soft          = (float)($params['softening']      ?? 5e5);
    $dt            = (float)($params['dt']             ?? 86400.0);
    $fragThreshold = (float)($params['frag_threshold'] ?? 0.0);
    $maxBodies     = (int)  ($params['max_bodies']     ?? 200);
    $simTime       = (float)($params['sim_time']       ?? 0.0);
    $maxBodies = min(max(3, $maxBodies), 1000);

    // Flat parallel arrays — fastest in PHP
    $bId = []; $bX = []; $bY = []; $bVx = []; $bVy = [];
    $bMass = []; $bRadius = []; $bFixed = []; $bActive = [];
    $nextId = 0;

    foreach ($bodiesRaw as $b) {
        $bId[]     = (int)  ($b['id']     ?? $nextId);
        $bX[]      = (float)($b['x']      ?? 0.0);
        $bY[]      = (float)($b['y']      ?? 0.0);
        $bVx[]     = (float)($b['vx']     ?? 0.0);
        $bVy[]     = (float)($b['vy']     ?? 0.0);
        $bMass[]   = (float)($b['mass']   ?? 1e15);
        $bRadius[] = (float)($b['radius'] ?? 1e4);
        $bFixed[]  = (bool) ($b['fixed']  ?? false);
        $bActive[] = true;
        $nextId    = max($nextId, (int)($b['id'] ?? 0)) + 1;
    }

    $frameStates = [];
    $collisions  = [];

    for ($step = 0; $step < $steps; $step++) {
        $n = count($bId);
        // Bail if nothing movable
        $nMovable = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($bActive[$i] && !$bFixed[$i]) $nMovable++;
        }
        if ($nMovable === 0) break;

        // Step 1: accelerations at current positions
        $ax = array_fill(0, $n, 0.0);
        $ay = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            if (!$bActive[$i] || $bFixed[$i]) continue;
            $axi = 0.0; $ayi = 0.0;
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i || !$bActive[$j]) continue;
                $dx = $bX[$j] - $bX[$i];
                $dy = $bY[$j] - $bY[$i];
                $r2 = $dx*$dx + $dy*$dy + $soft*$soft;
                $r  = sqrt($r2);
                $f  = $G * $bMass[$j] / ($r2 * $r);
                $axi += $f * $dx;
                $ayi += $f * $dy;
            }
            $ax[$i] = $axi; $ay[$i] = $ayi;
        }

        // Step 2: update positions
        $dt2 = 0.5 * $dt * $dt;
        for ($i = 0; $i < $n; $i++) {
            if (!$bActive[$i] || $bFixed[$i]) continue;
            $bX[$i] += $bVx[$i] * $dt + $ax[$i] * $dt2;
            $bY[$i] += $bVy[$i] * $dt + $ay[$i] * $dt2;
        }

        // Step 3: accelerations at new positions
        $ax2 = array_fill(0, $n, 0.0);
        $ay2 = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            if (!$bActive[$i] || $bFixed[$i]) continue;
            $axi = 0.0; $ayi = 0.0;
            for ($j = 0; $j < $n; $j++) {
                if ($j === $i || !$bActive[$j]) continue;
                $dx = $bX[$j] - $bX[$i];
                $dy = $bY[$j] - $bY[$i];
                $r2 = $dx*$dx + $dy*$dy + $soft*$soft;
                $r  = sqrt($r2);
                $f  = $G * $bMass[$j] / ($r2 * $r);
                $axi += $f * $dx;
                $ayi += $f * $dy;
            }
            $ax2[$i] = $axi; $ay2[$i] = $ayi;
        }

        // Step 4: update velocities
        $dth = 0.5 * $dt;
        for ($i = 0; $i < $n; $i++) {
            if (!$bActive[$i] || $bFixed[$i]) continue;
            $bVx[$i] += ($ax[$i] + $ax2[$i]) * $dth;
            $bVy[$i] += ($ay[$i] + $ay2[$i]) * $dth;
        }

        $simTime += $dt;

        // ── Collision / accretion detection ──────────────────────────────
        // Identify the most massive fixed body as the central mass reference
        $centralMass = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if ($bActive[$i] && $bFixed[$i] && $bMass[$i] > $centralMass) {
                $centralMass = $bMass[$i];
            }
        }

        $activeIdx = [];
        for ($i = 0; $i < $n; $i++) { if ($bActive[$i]) $activeIdx[] = $i; }
        usort($activeIdx, static fn($a, $b) => $bMass[$b] <=> $bMass[$a]);
        
        $deactivated = [];
        $ni = count($activeIdx);
        for ($ai = 0; $ai < $ni; $ai++) {
            $i = $activeIdx[$ai];
            if (isset($deactivated[$i])) continue;
            for ($aj = $ai + 1; $aj < $ni; $aj++) {
                $j = $activeIdx[$aj];
                if (isset($deactivated[$j])) continue;
                if ($bFixed[$i] && $bFixed[$j]) continue; // two fixed bodies never merge

                $dx  = $bX[$i] - $bX[$j];
                $dy  = $bY[$i] - $bY[$j];
                $sep = sqrt($dx*$dx + $dy*$dy);
                $totalM = $bMass[$i] + $bMass[$j];

                // Merge distance: mutual Hill radius around central mass.
                // If no central mass (free-field), use gravitational capture radius.
                if ($centralMass > 0.0) {
                    $ri = sqrt($bX[$i]*$bX[$i] + $bY[$i]*$bY[$i]);
                    $rj = sqrt($bX[$j]*$bX[$j] + $bY[$j]*$bY[$j]);
                    $a_avg = max($soft, 0.5 * ($ri + $rj));
                    $mergeDist = $a_avg * pow($totalM / (3.0 * $centralMass), 1.0/3.0);
                } else {
                    $dvx = $bVx[$i] - $bVx[$j];
                    $dvy = $bVy[$i] - $bVy[$j];
                    $vRel2 = $dvx*$dvx + $dvy*$dvy;
                    $mergeDist = ($vRel2 > 0.0)
                        ? $G * $totalM / $vRel2
                        : $bRadius[$i] + $bRadius[$j];
                }

                $physDist  = $bRadius[$i] + $bRadius[$j];
                $threshold = max($physDist, $mergeDist);
                if ($sep > $threshold) continue;

                $dvx   = $bVx[$i] - $bVx[$j];
                $dvy   = $bVy[$i] - $bVy[$j];
                $mu    = $bMass[$i] * $bMass[$j] / $totalM;
                $relKE = 0.5 * $mu * ($dvx*$dvx + $dvy*$dvy);
                $doFrag = false;

                if ($fragThreshold > 0.0) {
                    $qD    = $relKE / $totalM;
                    $rMin  = min($bRadius[$i], $bRadius[$j]);
                    $qStar = 3e5 * pow(max($rMin, 1.0), -0.24);
                    $doFrag = $qD > $fragThreshold * $qStar;
                }

                if (!$doFrag) {
                    // Perfectly inelastic merge
                    $bVx[$i] = ($bMass[$i]*$bVx[$i] + $bMass[$j]*$bVx[$j]) / $totalM;
                    $bVy[$i] = ($bMass[$i]*$bVy[$i] + $bMass[$j]*$bVy[$j]) / $totalM;
                    $bX[$i]  = ($bMass[$i]*$bX[$i]  + $bMass[$j]*$bX[$j])  / $totalM;
                    $bY[$i]  = ($bMass[$i]*$bY[$i]  + $bMass[$j]*$bY[$j])  / $totalM;
                    $rho     = $bMass[$i] / max(1.0, 4.18879 * $bRadius[$i]**3);
                    $bMass[$i]   = $totalM;
                    $bRadius[$i] = pow($totalM / max(1e-30, 4.18879 * $rho), 1.0/3.0);
                    $bActive[$j]     = false;
                    $deactivated[$j] = true;
                    $collisions[] = [
                        't' => round($simTime, 2), 'type' => 'merge',
                        'id_a' => $bId[$i], 'id_b' => $bId[$j],
                        'ke_diss' => $relKE, 'result_mass' => $bMass[$i],
                    ];
                } else {
                    // Fragmentation
                    $nNow = count($activeIdx) - count($deactivated);
                    if ($nNow >= $maxBodies) {
                        // Cap reached, fallback to merge
                        $bVx[$i] = ($bMass[$i]*$bVx[$i] + $bMass[$j]*$bVx[$j]) / $totalM;
                        $bVy[$i] = ($bMass[$i]*$bVy[$i] + $bMass[$j]*$bVy[$j]) / $totalM;
                        $bX[$i]  = ($bMass[$i]*$bX[$i]  + $bMass[$j]*$bX[$j])  / $totalM;
                        $bY[$i]  = ($bMass[$i]*$bY[$i]  + $bMass[$j]*$bY[$j])  / $totalM;
                        $rho     = $bMass[$i] / max(1.0, 4.18879 * $bRadius[$i]**3);
                        $bMass[$i]   = $totalM;
                        $bRadius[$i] = pow($totalM / max(1e-30, 4.18879 * $rho), 1.0/3.0);
                        $bActive[$j] = false; $deactivated[$j] = true;
                        continue;
                    }

                    $rDist = sqrt(max(1.0, $dx*$dx + $dy*$dy));
                    $qD    = $relKE / $totalM;
                    $rMin  = min($bRadius[$i], $bRadius[$j]);
                    $qStar = 3e5 * pow(max($rMin, 1.0), -0.24);
                    $frac  = min(0.5, 0.1 * ($qD / $qStar));
                    $mFrag = $bMass[$j] * $frac;
                    
                    $bMass[$j]  -= $mFrag;
                    $rhoJ = $bMass[$j] / max(1.0, 4.18879 * $bRadius[$j]**3);
                    $bRadius[$j] = pow($bMass[$j] / max(1e-30, 4.18879 * $rhoJ), 1.0/3.0);
                    
                    $nvx = $dx / $rDist; $nvy = $dy / $rDist;
                    $spd = sqrt(max(0.0, 2.0 * $relKE / max(1e-30, $mFrag))) * 0.4;
                    
                    $bId[]     = $nextId++;
                    $bX[]      = $bX[$j] + $nvx * $bRadius[$j] * 2.0;
                    $bY[]      = $bY[$j] + $nvy * $bRadius[$j] * 2.0;
                    $bVx[]     = $bVx[$j] + $nvx * $spd;
                    $bVy[]     = $bVy[$j] + $nvy * $spd;
                    $bMass[]   = $mFrag;
                    $rhoF      = max(1e-30, $bMass[$j] / max(1.0, 4.18879 * $bRadius[$j]**3));
                    $bRadius[] = pow($mFrag / max(1e-30, 4.18879 * $rhoF), 1.0/3.0);
                    $bFixed[]  = false; $bActive[] = true;
                    
                    $collisions[] = [
                        't' => round($simTime, 2), 'type' => 'frag',
                        'id_a' => $bId[$i], 'id_b' => $bId[$j],
                        'ke_diss' => $relKE * $frac, 'result_mass' => $mFrag,
                    ];
                }
            }
        }

        // Snapshot frame
        $n = count($bId);
        $fBodies = []; $totalKE = 0.0; $totalPE = 0.0; $mMax = 0.0; $nAlive = 0;
        for ($i = 0; $i < $n; $i++) {
            if (!$bActive[$i]) continue;
            $nAlive++;
            $spd2     = $bVx[$i]*$bVx[$i] + $bVy[$i]*$bVy[$i];
            $totalKE += 0.5 * $bMass[$i] * $spd2;
            if ($bMass[$i] > $mMax) $mMax = $bMass[$i];
            
            if (!$bFixed[$i]) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (!$bActive[$j]) continue;
                    $ddx = $bX[$i] - $bX[$j]; $ddy = $bY[$i] - $bY[$j];
                    $rr  = sqrt($ddx*$ddx + $ddy*$ddy);
                    if ($rr > 0.0) $totalPE -= $G * $bMass[$i] * $bMass[$j] / $rr;
                }
            }
            $fBodies[] = [
                (int)$bId[$i], (int)round($bX[$i]), (int)round($bY[$i]),
                round($bVx[$i], 6), round($bVy[$i], 6),
                $bMass[$i], (int)round($bRadius[$i]), $bFixed[$i] ? 1 : 0,
            ];
        }
        $frameStates[] = [
            'bodies'   => $fBodies,
            't'        => round($simTime, 2),
            'n'        => $nAlive,
            'm_max'    => $mMax,
            'total_ke' => $totalKE,
            'total_pe' => $totalPE,
        ];
    }
    return [
        'states'      => $frameStates,
        'collisions'  => $collisions,
        'finalTime'   => $simTime,
        'finalBodies' => serializeBodiesAst(
            $bId, $bX, $bY, $bVx, $bVy, $bMass, $bRadius, $bFixed, $bActive
        ),
    ];
}

function serializeBodiesAst(
    array $id, array $x, array $y, array $vx, array $vy,
    array $mass, array $radius, array $fixed, array $active
): array {
    $out = [];
    foreach ($id as $i => $_) {
        if (!$active[$i]) continue;
        $out[] = [
            'id'     => (int)$id[$i],
            'x'      => round($x[$i],  0),
            'y'      => round($y[$i],  0),
            'vx'     => round($vx[$i], 6),
            'vy'     => round($vy[$i], 6),
            'mass'   => $mass[$i],
            'radius' => round($radius[$i], 0),
            'fixed'  => $fixed[$i],
        ];
    }
    return $out;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
<title>Gravity · Asteroid Belt · Trial</title>
<link rel="stylesheet" href="/assets/css/sim-ui.css">
<link rel="stylesheet" href="/assets/css/sim-gravity-asteroid.css">
<script>
window.addEventListener('message', function(e) {
  if (!e.data || e.data.type !== 'ir-theme') return;
  var t = e.data.theme;
  if (t !== 'light' && t !== 'dark') return;
  document.documentElement.setAttribute('data-theme', t);
  window.__irTheme = t;
  simColors = null;
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

<div class="side-backdrop" id="sideBackdrop" onclick="closePanel()"></div>

<div class="side-panel" id="sidePanel">
  <div class="side-panel-header">
    <span class="side-panel-title">Settings</span>
    <button class="side-panel-close" onclick="closePanel()" aria-label="Close">&#x2715;</button>
  </div>
  <div class="side-panel-body">
    <div class="side-section-label">Central Mass</div>
    <div class="central-mass-row" id="cmRow">
      <button class="cm-chip active" onclick="setCentralMass('star')"  data-cm="star">Solar Star</button>
      <button class="cm-chip"        onclick="setCentralMass('proto')" data-cm="proto">Protostar</button>
      <button class="cm-chip"        onclick="setCentralMass('earth')" data-cm="earth">Super-Earth</button>
      <button class="cm-chip locked-chip" title="Pro only" data-cm="custom">Custom</button>
    </div>

    <div class="side-section-label">Time Scale</div>
    <div class="slider-group">
      <div class="timescale-label">
        <span class="ts-name">Sim speed</span>
        <span class="ts-val" id="vTS">1 yr/s</span>
      </div>
      <input type="range" id="sTS" min="0" max="4" step="1" value="3" oninput="setTimeScale(+this.value)">
      <div style="display:flex;justify-content:space-between;font-family:var(--font-mono);font-size:0.5rem;color:var(--text-muted);margin-top:2px;">
        <span>0.1yr</span><span>0.25yr</span><span>0.5yr</span><span>1yr</span><span>5yr</span>
      </div>
    </div>

    <div class="side-section-label">Physics</div>
    <div class="slider-grid">
      <div class="slider-group">
        <div class="slider-label"><span class="name">G Multiplier</span><span class="val" id="vGm">1.0x</span></div>
        <input type="range" id="sGm" min="-1" max="1" step="0.1" value="0" oninput="setGMult(+this.value)">
      </div>
      <div class="slider-group">
        <div class="slider-label"><span class="name">Softening (km)</span><span class="val" id="vSoft">500</span></div>
        <input type="range" id="sSoft" min="1" max="4" step="1" value="2" oninput="setSoftening(+this.value)">
      </div>
    </div>

    <div class="side-section-label">Actions</div>
    <div class="action-row">
      <button class="act-btn spawn-pulse" id="spawnBtn" onclick="spawnBody()">+ Body</button>
      <button class="act-btn" onclick="hardReset()">Reset</button>
      <button class="act-btn" onclick="applyPreset('belt')">Belt</button>
      <button class="act-btn" onclick="applyPreset('disk')">Disk</button>
      <button class="act-btn" onclick="applyPreset('chaos')">Chaos</button>
    </div>
  </div>
</div>

<div class="panel active" id="panelSim">
  <div class="trial-badge">Trial Version · 30 Bodies · Merge Only</div>
  <div class="canvas-zone pannable" id="canvasZone">
    <canvas id="simCanvas"></canvas>
    <div class="body-count-badge">
      <span class="lbl">N</span><span class="val" id="hudN">3</span>
      <span class="lbl">M</span><span class="val" id="hudMmax">—</span>
    </div>
    <button class="settings-btn" id="settingsBtn" onclick="openPanel()" aria-label="Settings">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </button>
    <div class="hud">
      <div class="hud-pill"><span class="lbl">t</span><span class="val" id="hudTime">0 yr</span></div>
      <div class="hud-pill"><span class="lbl">KE</span><span class="val" id="hudKE">—</span><span class="lbl">PE</span><span class="val" id="hudPE">—</span></div>
    </div>
    <div class="event-ticker" id="eventTicker"></div>
  </div>
</div>

<div class="panel" id="panelAnal">
  <div class="analysis-grid">
    <div class="plot-cell"><div class="plot-label">N(t) Body Count</div><canvas id="cN"></canvas></div>
    <div class="plot-cell"><div class="plot-label">Top-3 Mass vs Time</div><canvas id="cMass"></canvas></div>
    <div class="plot-cell" style="position:relative;overflow:hidden;">
      <div class="plot-label">Size-Frequency Distribution (log-log)</div>
      <div style="position:absolute;inset:0;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);background:var(--lock-overlay-bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;z-index:5;">
        <span style="font-size:1.1rem">🔒</span>
        <span style="font-family:var(--font-mono);font-size:0.6rem;color:var(--color-accent);letter-spacing:.1em;text-transform:uppercase">Pro Feature</span>
        <span style="font-size:0.62rem;color:var(--text-muted);text-align:center;max-width:160px;line-height:1.4">Log-log SFD unlocked in full version</span>
      </div>
    </div>
    <div class="plot-cell" style="position:relative;overflow:hidden;">
      <div class="plot-label">Accretion vs Fragmentation Ratio</div>
      <div style="position:absolute;inset:0;backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);background:var(--lock-overlay-bg);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;z-index:5;">
        <span style="font-size:1.1rem">🔒</span>
        <span style="font-family:var(--font-mono);font-size:0.6rem;color:var(--color-accent);letter-spacing:.1em;text-transform:uppercase">Pro Feature</span>
        <span style="font-size:0.62rem;color:var(--text-muted);text-align:center;max-width:160px;line-height:1.4">Collision ratio unlocked in full version</span>
      </div>
    </div>
  </div>
</div>

<div class="panel" id="panelData">
  <div class="data-panel-inner">
    <div class="data-toolbar">
      <button class="data-btn" style="opacity:0.4;cursor:not-allowed;" disabled>CSV (Body Log)</button>
      <button class="data-btn" style="opacity:0.4;cursor:not-allowed;" disabled>CSV (Collision Log)</button>
      <span style="margin-left:auto;font-family:var(--font-mono);font-size:0.58rem;color:var(--color-accent);">Preview · Pro exports full log</span>
    </div>
    <div class="data-stats">
      <div class="stat-cell"><div class="s-lbl">N Remaining</div><div class="s-val" id="statN">—</div></div>
      <div class="stat-cell"><div class="s-lbl">M Largest</div><div class="s-val" id="statML">—</div></div>
      <div class="stat-cell"><div class="s-lbl">Merges</div><div class="s-val" id="statMerge">0</div></div>
      <div class="stat-cell"><div class="s-lbl">KE Diss.</div><div class="s-val" id="statKEdiss">—</div></div>
    </div>
    <div class="table-wrap" id="tableWrap">
      <table>
        <thead><tr><th>t (yr)</th><th>N</th><th>id</th><th>mass (kg)</th><th>r (m)</th><th>x (AU)</th><th>y (AU)</th><th>|v| (m/s)</th></tr></thead>
        <tbody id="dataTableBody"></tbody>
      </table>
    </div>
    <div style="padding:8px 14px;font-family:var(--font-mono);font-size:0.58rem;color:var(--text-muted);">Showing last 10 snapshots.</div>
  </div>
</div>

<script>
/* ─────────────────────────────────────────────────────────────────────────────
   CONSTANTS
───────────────────────────────────────────────────────────────────────────── */
const API_PATH         = '?_engine=1';
const BATCH_STEPS      = 60;
const MAX_BODIES_TRIAL = 30;
const AU               = 1.496e11;
const YEAR_S           = 3.156e7;

/*
 * TIME_SCALES[i] = simulated seconds per wall-second at 60 fps.
 * getSimDt() returns simulated seconds per single integration step.
 *   simDt = TIME_SCALES[i] / 60
 *
 * Time-based playback:
 *   Each RAF call measures elapsed wall ms. We convert that to simulated
 *   seconds, divide by simDt to get how many frames to advance, and
 *   consume that many frames (capped at MAX_FRAMES_PER_RAF).
 *   This makes playback speed proportional to the time-scale slider
 *   regardless of actual frame rate.
 */
const TIME_SCALES = [
  YEAR_S * 0.1,
  YEAR_S * 0.25,
  YEAR_S * 0.5,
  YEAR_S * 1.0,
  YEAR_S * 5.0,
];
const TIME_SCALE_LABELS = ['0.1 yr/s','0.25 yr/s','0.5 yr/s','1 yr/s','5 yr/s'];
const MAX_FRAMES_PER_RAF = 3;

const CENTRAL_MASSES = {star:1.989e30, proto:1.989e29, earth:2.988e25, custom:null};
const SOFTENING_MAP  = [1e5, 2e5, 5e5, 1e6];
const G_SI = 6.674e-11;

/* ─────────────────────────────────────────────────────────────────────────────
   SIM STATE
───────────────────────────────────────────────────────────────────────────── */
let params = {centralMass:'star', G_mult:1.0, softening:5e5, timeScaleIdx:3, fragThreshold:0.0, bodyCount:30};
let simTime   = 0;

/*
 * bodies[] — only used as input to the next fetchBatch call.
 * It is ALWAYS rebuilt from the last consumed frame's body list.
 * It is NEVER mutated in-place from any server response or frame data.
 *
 * lastConsumedFrame — the most recently rendered frame object.
 * fetchBatch uses lastConsumedFrame.bodies and lastConsumedFrame.t
 * as the integration starting point, guaranteeing continuity.
 */
let bodies           = [];
let lastConsumedFrame = null;
let nextBodyId = 0;

let collisionLog = [];
let mergeCount   = 0;
let totalKEDiss  = 0;

let buf     = [];
let bufHead = 0;
let fetching = false;
let activeTab = 'sim';
let avgRTT    = 300;
let rttSamples = [];

/* Wall-clock accumulator for time-based frame advancement */
let lastRafTs  = 0;
let simTimeAcc = 0;   /* accumulated simulation seconds not yet consumed */

const nPts = [], massPts = [];
let lastPlotTs = 0;
const PLOT_FPS = 10;
const MAX_ANAL = 1200;

const dataLog    = [];
const MAX_ROWS   = 10;
let pendingRows  = [];
let tableRowCount = 0;
const TABLE_FLUSH = 600;

let camX=0, camY=0, camScale=1;
let pinchDist0=null, panStart=null, isDragging=false;

let simColors=null;
function buildSimColors(){
  const s=getComputedStyle(document.documentElement);
  const v=n=>s.getPropertyValue(n).trim();
  simColors={bg:v('--bg-primary'),muted:v('--text-muted'),accent:v('--color-accent'),
    success:v('--color-success'),danger:v('--color-danger'),
    dust:v('--body-dust'),rock:v('--body-rock'),planetes:v('--body-planetesimal'),
    planet:v('--body-planet'),central:v('--body-central'),
    dustRgb:v('--body-dust-rgb'),rockRgb:v('--body-rock-rgb'),
    planetesRgb:v('--body-planetesimal-rgb'),planetRgb:v('--body-planet-rgb'),
    centralRgb:v('--body-central-rgb'),border:v('--border-color')};
}

/* ─────────────────────────────────────────────────────────────────────────────
   CANVAS
───────────────────────────────────────────────────────────────────────────── */
const simCanvas=document.getElementById('simCanvas');
const ctx=simCanvas.getContext('2d');
const dpr=window.devicePixelRatio||1;

function resizeSim(){
  const z=simCanvas.parentElement;const w=z.clientWidth,h=z.clientHeight;
  simCanvas.width=w*dpr;simCanvas.height=h*dpr;
  simCanvas.style.width=w+'px';simCanvas.style.height=h+'px';
  ctx.setTransform(dpr,0,0,dpr,0,0);
}
function resizePlot(id){
  const c=document.getElementById(id);if(!c)return;
  const cell=c.parentElement;const w=cell.clientWidth,h=cell.clientHeight;
  if(w<2||h<2)return;
  c.width=w*dpr;c.height=h*dpr;c.style.width=w+'px';c.style.height=h+'px';
  c.getContext('2d').setTransform(dpr,0,0,dpr,0,0);
}
window.addEventListener('resize',()=>{resizeSim();if(activeTab==='anal')resizeAnalPlots();});
resizeSim();

/* ─────────────────────────────────────────────────────────────────────────────
   BODY COLOR
───────────────────────────────────────────────────────────────────────────── */
function bodyColor(mass,fixed){if(!simColors)buildSimColors();if(fixed)return simColors.central;if(mass<1e12)return simColors.dust;if(mass<1e18)return simColors.rock;if(mass<1e23)return simColors.planetes;return simColors.planet;}
function bodyColorRgb(mass,fixed){if(!simColors)buildSimColors();if(fixed)return simColors.centralRgb;if(mass<1e12)return simColors.dustRgb;if(mass<1e18)return simColors.rockRgb;if(mass<1e23)return simColors.planetesRgb;return simColors.planetRgb;}

/* ─────────────────────────────────────────────────────────────────────────────
   WORLD ↔ SCREEN
───────────────────────────────────────────────────────────────────────────── */
function worldToScreen(wx,wy){const W=simCanvas.clientWidth,H=simCanvas.clientHeight;return[W/2+(wx-camX)*camScale,H/2-(wy-camY)*camScale];}
function autoFitCamera(){
  if(!bodies.length)return;
  let minX=Infinity,maxX=-Infinity,minY=Infinity,maxY=-Infinity;
  for(const b of bodies){if(b.x<minX)minX=b.x;if(b.x>maxX)maxX=b.x;if(b.y<minY)minY=b.y;if(b.y>maxY)maxY=b.y;}
  const W=simCanvas.clientWidth,H=simCanvas.clientHeight;
  const span=Math.max(maxX-minX,maxY-minY)*1.4||2*AU;
  camScale=Math.min(W,H)/span;camX=(minX+maxX)/2;camY=(minY+maxY)/2;
}

/* ─────────────────────────────────────────────────────────────────────────────
   DRAW
───────────────────────────────────────────────────────────────────────────── */
function drawSim(){
  if(!simColors)buildSimColors();
  const W=simCanvas.clientWidth,H=simCanvas.clientHeight;
  ctx.clearRect(0,0,W,H);
  ctx.fillStyle=simColors.bg;ctx.fillRect(0,0,W,H);
  const gs=40;ctx.fillStyle='rgba(128,128,128,0.08)';
  for(let gx=(W/2)%gs;gx<W;gx+=gs)for(let gy=(H/2)%gs;gy<H;gy+=gs){ctx.beginPath();ctx.arc(gx,gy,0.8,0,Math.PI*2);ctx.fill();}
  for(const b of bodies){
    const [sx,sy]=worldToScreen(b.x,b.y);
    const sr=Math.max(2,b.radius*camScale);
    if(b.fixed){
      const rgb=simColors.centralRgb;
      for(let g=3;g>=1;g--){ctx.beginPath();ctx.arc(sx,sy,sr*(1+g*0.5),0,Math.PI*2);ctx.fillStyle=`rgba(${rgb},${0.06/g})`;ctx.fill();}
      const grad=ctx.createRadialGradient(sx-sr*0.3,sy-sr*0.3,0,sx,sy,sr);
      grad.addColorStop(0,`rgba(${rgb},1.0)`);grad.addColorStop(0.6,simColors.central);grad.addColorStop(1,`rgba(${rgb},0.6)`);
      ctx.beginPath();ctx.arc(sx,sy,sr,0,Math.PI*2);ctx.fillStyle=grad;ctx.fill();
    }else{
      const rgb=bodyColorRgb(b.mass,false);const col=bodyColor(b.mass,false);
      const grad=ctx.createRadialGradient(sx-sr*0.3,sy-sr*0.3,0,sx,sy,sr);
      grad.addColorStop(0,`rgba(${rgb},1.0)`);grad.addColorStop(1,`rgba(${rgb},0.5)`);
      ctx.beginPath();ctx.arc(sx,sy,sr,0,Math.PI*2);ctx.fillStyle=grad;ctx.fill();
      ctx.strokeStyle=col;ctx.lineWidth=0.5;ctx.stroke();
    }
  }
}

/* ─────────────────────────────────────────────────────────────────────────────
   CLIENT FALLBACK (N² Euler)
───────────────────────────────────────────────────────────────────────────── */
function getSimDt(){return TIME_SCALES[params.timeScaleIdx]/60;}

function clientFallbackBatch(seedBodies,seedTime){
  const dt=getSimDt(),G=G_SI*params.G_mult,soft=params.softening;
  const frames=[];
  const bs=seedBodies.map(b=>({...b}));
  let t=seedTime;

  for(let step=0;step<BATCH_STEPS;step++){
    for(let i=0;i<bs.length;i++){
      bs[i]._ax=0;bs[i]._ay=0;if(bs[i].fixed)continue;
      for(let j=0;j<bs.length;j++){
        if(i===j)continue;
        const dx=bs[j].x-bs[i].x,dy=bs[j].y-bs[i].y;
        const r2=dx*dx+dy*dy+soft*soft;const r=Math.sqrt(r2);
        const f=G*bs[j].mass/(r2*r);bs[i]._ax+=f*dx;bs[i]._ay+=f*dy;
      }
    }
    for(const b of bs){if(b.fixed)continue;b.vx+=b._ax*dt;b.vy+=b._ay*dt;b.x+=b.vx*dt;b.y+=b.vy*dt;}
    t+=dt;
    const toRemove=new Set();
    for(let i=0;i<bs.length;i++){if(toRemove.has(i))continue;
      for(let j=i+1;j<bs.length;j++){if(toRemove.has(j))continue;
        const dx=bs[i].x-bs[j].x,dy=bs[i].y-bs[j].y;const r=Math.sqrt(dx*dx+dy*dy);
        if(r<bs[i].radius+bs[j].radius){
          const tm=bs[i].mass+bs[j].mass;
          bs[i].vx=(bs[i].mass*bs[i].vx+bs[j].mass*bs[j].vx)/tm;
          bs[i].vy=(bs[i].mass*bs[i].vy+bs[j].mass*bs[j].vy)/tm;
          bs[i].x=(bs[i].mass*bs[i].x+bs[j].mass*bs[j].x)/tm;
          bs[i].y=(bs[i].mass*bs[i].y+bs[j].mass*bs[j].y)/tm;
          const rho=bs[i].mass/(4/3*Math.PI*bs[i].radius**3);
          bs[i].radius=Math.cbrt(tm/(4/3*Math.PI*rho));bs[i].mass=tm;
          toRemove.add(j);mergeCount++;
        }
      }
    }
    for(const ri of[...toRemove].sort((a,b)=>b-a))bs.splice(ri,1);
    const snapshot=bs.map(b=>[b.id,b.x,b.y,b.vx,b.vy,b.mass,b.radius,b.fixed?1:0]);
    let mMax=0,ke=0;for(const b of bs){if(b.mass>mMax)mMax=b.mass;ke+=0.5*b.mass*(b.vx*b.vx+b.vy*b.vy);}
    frames.push({bodies:snapshot,t,n:bs.length,m_max:mMax,total_ke:ke,total_pe:0});
  }
  buf.push(...frames);
}

/* ─────────────────────────────────────────────────────────────────────────────
   BATCH FETCH
   Takes the last consumed frame as seed so the server always continues
   from exactly where rendering left off. No time mismatch possible.
───────────────────────────────────────────────────────────────────────────── */
async function fetchBatch(){
  if(fetching||!lastConsumedFrame)return;
  fetching=true;
  const seedBodies=lastConsumedFrame.bodies.map(fb=>{
    const[fid,fx,fy,fvx,fvy,fm,fr,ffixed]=fb;
    return{id:fid,x:fx,y:fy,vx:fvx,vy:fvy,mass:fm,radius:fr,fixed:ffixed===1};
  });
  const seedTime=lastConsumedFrame.t;
  const t0=performance.now();
  try{
    const payload=new URLSearchParams({
      action:'batch',type:'gravity-asteroid',steps:BATCH_STEPS,
      bodies:JSON.stringify(seedBodies),G:G_SI*params.G_mult,
      softening:params.softening,dt:getSimDt(),
      frag_threshold:params.fragThreshold,max_bodies:MAX_BODIES_TRIAL,
      sim_time:seedTime,
    });
    const res=await fetch(API_PATH,{method:'POST',body:payload});
    const json=await res.json();
    const rtt=performance.now()-t0;
    rttSamples.push(rtt);if(rttSamples.length>8)rttSamples.shift();
    avgRTT=rttSamples.reduce((a,b)=>a+b,0)/rttSamples.length;
    if(json.states&&json.states.length){
      /* Only trim if buffer is deep — keep at most 30 unconsumed ahead */
      if(buf.length-bufHead>30){buf.splice(0,bufHead);bufHead=0;}
      buf.push(...json.states);
    }
    if(json.collisions){
      for(const c of json.collisions){
        collisionLog.push(c);
        if(c.type==='merge'){mergeCount++;totalKEDiss+=c.ke_diss||0;}
        showEventTick(c.type,c.result_mass);
      }
    }
    /* Do NOT use json.finalBodies to update bodies[].
       bodies[] is rebuilt solely from frame data in the render loop. */
  }catch(e){
    clientFallbackBatch(seedBodies,seedTime);
  }finally{fetching=false;}
}

/* ─────────────────────────────────────────────────────────────────────────────
   RENDER LOOP — time-based frame advancement
   wallDt (ms) → sim seconds = wallDt * TIME_SCALES[i] / 1000
   frames to advance = simSeconds / getSimDt()
   Capped at MAX_FRAMES_PER_RAF to prevent spiral-of-death on tab re-focus.
   Fetch triggered only when buffer is nearly empty (< 15%).
───────────────────────────────────────────────────────────────────────────── */
function renderLoop(ts){
  requestAnimationFrame(renderLoop);

  const avail=buf.length-bufHead;
  const pct=Math.min(1,avail/BATCH_STEPS);

  /* Trigger next batch only when buffer is nearly empty */
  if(!fetching&&lastConsumedFrame&&pct<0.15)fetchBatch();

  if(bufHead>=buf.length){lastRafTs=ts;return;}

  /* Time-based advancement */
  const wallDtMs=lastRafTs===0?16.7:Math.min(ts-lastRafTs,100);
  lastRafTs=ts;
  simTimeAcc+=wallDtMs*0.001*TIME_SCALES[params.timeScaleIdx];

  const simDt=getSimDt();
  const framesToAdv=Math.min(MAX_FRAMES_PER_RAF,Math.max(1,Math.floor(simTimeAcc/simDt)));
  simTimeAcc-=framesToAdv*simDt;
  if(simTimeAcc<0)simTimeAcc=0;

  let frame;
  for(let f=0;f<framesToAdv;f++){
    if(bufHead>=buf.length)break;
    frame=buf[bufHead++];
  }
  if(!frame)return;

  /*
   * Rebuild bodies[] completely from this frame.
   * This is the ONLY place bodies[] is written.
   * lastConsumedFrame is updated so the next fetch seeds from here.
   */
  lastConsumedFrame=frame;
  bodies=frame.bodies.map(fb=>{
    const[fid,fx,fy,fvx,fvy,fm,fr,ffixed]=fb;
    return{id:fid,x:fx,y:fy,vx:fvx,vy:fvy,mass:fm,radius:fr,fixed:ffixed===1};
  });

  simTime=frame.t;

  /* HUD */
  document.getElementById('hudTime').textContent=(simTime/YEAR_S).toFixed(2)+' yr';
  document.getElementById('hudN').textContent=frame.n;
  document.getElementById('hudMmax').textContent=fmtMass(frame.m_max);
  document.getElementById('hudKE').textContent=fmtEnergy(frame.total_ke);
  document.getElementById('hudPE').textContent=fmtEnergy(frame.total_pe);
  document.getElementById('statN').textContent=frame.n;
  document.getElementById('statML').textContent=fmtMass(frame.m_max);
  document.getElementById('statMerge').textContent=mergeCount;
  document.getElementById('statKEdiss').textContent=fmtEnergy(totalKEDiss);

  drawSim();

  nPts.push([simTime/YEAR_S,frame.n]);
  const sorted=bodies.filter(b=>!b.fixed).sort((a,b)=>b.mass-a.mass);
  massPts.push([simTime/YEAR_S,sorted[0]?.mass||0,sorted[1]?.mass||0,sorted[2]?.mass||0]);
  if(nPts.length>MAX_ANAL){nPts.shift();massPts.shift();}

  if(bufHead%6===0){
    for(const b of bodies){const speed=Math.sqrt(b.vx*b.vx+b.vy*b.vy);pendingRows.push([simTime/YEAR_S,frame.n,b.id,b.mass,b.radius,b.x/AU,b.y/AU,speed]);}
    if(dataLog.length<MAX_ROWS)dataLog.push({t:simTime/YEAR_S,n:frame.n,bodies:bodies.slice()});
  }
  if(activeTab==='anal'&&ts-lastPlotTs>1000/PLOT_FPS){lastPlotTs=ts;refreshAnalPlots();}
}

/* ─────────────────────────────────────────────────────────────────────────────
   ANALYSIS PLOTS
───────────────────────────────────────────────────────────────────────────── */
function resizeAnalPlots(){['cN','cMass'].forEach(resizePlot);}
function plotPad(W,H){return Math.max(20,Math.min(40,Math.min(W,H)*0.07));}
function axFont(W){return Math.max(9,Math.min(11,W*0.026))+'px '+getComputedStyle(document.documentElement).getPropertyValue('--font-mono').trim();}

function drawLineChart(canvasId,series,colors,xLabel,yLabel){
  resizePlot(canvasId);
  const c=document.getElementById(canvasId);if(!c)return;
  const cx=c.getContext('2d');const W=c.clientWidth,H=c.clientHeight;
  cx.clearRect(0,0,W,H);
  if(!series.length||series[0].length<2)return;
  if(!simColors)buildSimColors();
  const p=plotPad(W,H);
  let minX=Infinity,maxX=-Infinity,minY=Infinity,maxY=-Infinity;
  for(const pts of series)for(const[x,y]of pts){if(x<minX)minX=x;if(x>maxX)maxX=x;if(y<minY)minY=y;if(y>maxY)maxY=y;}
  const sx=v=>p+(v-minX)/(maxX-minX+1e-30)*(W-2*p);
  const sy=v=>H-p-(v-minY)/(maxY-minY+1e-30)*(H-2*p);
  cx.strokeStyle='rgba(128,128,128,0.1)';cx.lineWidth=1;
  cx.beginPath();cx.moveTo(p,H/2);cx.lineTo(W-p,H/2);cx.stroke();
  cx.fillStyle=simColors.muted;cx.font=axFont(W);cx.globalAlpha=0.55;
  cx.fillText(xLabel,W-p-20,H-p+12);cx.fillText(yLabel,p+2,p+10);cx.globalAlpha=1;
  for(let si=0;si<series.length;si++){
    const pts=series[si];if(pts.length<2)continue;
    cx.beginPath();cx.moveTo(sx(pts[0][0]),sy(pts[0][1]));
    for(let i=1;i<pts.length;i++)cx.lineTo(sx(pts[i][0]),sy(pts[i][1]));
    cx.strokeStyle=colors[si%colors.length];cx.lineWidth=1.5;cx.stroke();
    const[lx,ly]=pts[pts.length-1];
    cx.beginPath();cx.arc(sx(lx),sy(ly),3,0,Math.PI*2);cx.fillStyle=colors[si%colors.length];cx.fill();
  }
}
function plotN(){if(nPts.length<2)return;drawLineChart('cN',[nPts],['rgba(23,162,184,0.85)'],'t (yr)','N');}
function plotMass(){
  if(massPts.length<2)return;
  const s0=massPts.map(r=>[r[0],r[1]]),s1=massPts.map(r=>[r[0],r[2]]),s2=massPts.map(r=>[r[0],r[3]]);
  drawLineChart('cMass',[s0,s1,s2],['rgba(240,192,64,0.9)','rgba(23,162,184,0.85)','rgba(220,53,69,0.8)'],'t (yr)','mass');
}
function refreshAnalPlots(){plotN();plotMass();}

/* ─────────────────────────────────────────────────────────────────────────────
   DATA TABLE
───────────────────────────────────────────────────────────────────────────── */
const tbody=document.getElementById('dataTableBody');
function flushRows(){
  if(activeTab!=='data'||!pendingRows.length)return;
  const frag=document.createDocumentFragment();
  for(const row of pendingRows){
    if(tableRowCount>=MAX_ROWS)break;
    const tr=document.createElement('tr');
    tr.innerHTML=row.map((v,i)=>`<td>${i===0?v.toFixed(3):i===1?v:i===2?v:i===3?v.toExponential(3):i===4?v.toExponential(3):i===5?v.toFixed(4):i===6?v.toFixed(4):v.toFixed(2)}</td>`).join('');
    frag.appendChild(tr);tableRowCount++;
  }
  tbody.appendChild(frag);pendingRows=[];
}
setInterval(flushRows,TABLE_FLUSH);

/* ─────────────────────────────────────────────────────────────────────────────
   EVENT TICKER
───────────────────────────────────────────────────────────────────────────── */
function showEventTick(type,mass){
  const ticker=document.getElementById('eventTicker');
  const el=document.createElement('span');
  el.className='event-tick '+(type==='frag'?'frag':'merge');
  el.textContent=type==='merge'?'Merge: '+fmtMass(mass):'Frag: '+fmtMass(mass);
  ticker.appendChild(el);
  requestAnimationFrame(()=>el.classList.add('show'));
  setTimeout(()=>{el.classList.remove('show');setTimeout(()=>el.remove(),300);},2000);
}

/* ─────────────────────────────────────────────────────────────────────────────
   FORMAT HELPERS
───────────────────────────────────────────────────────────────────────────── */
function fmtMass(m){if(!m||m===0)return '—';if(m>=1e30)return(m/1.989e30).toFixed(2)+' M\u2609';if(m>=1e24)return(m/5.972e24).toFixed(2)+' M\u2295';if(m>=1e18)return(m/1e18).toFixed(2)+' Eg';return m.toExponential(2)+' kg';}
function fmtEnergy(e){if(!e||e===0)return '—';return e.toExponential(2)+' J';}

/* ─────────────────────────────────────────────────────────────────────────────
   PRESET BODY CONFIGURATIONS
───────────────────────────────────────────────────────────────────────────── */
function makeCentralBody(){const cm=CENTRAL_MASSES[params.centralMass];const r=Math.cbrt(cm/(5513*4/3*Math.PI))*5;return{id:nextBodyId++,x:0,y:0,vx:0,vy:0,mass:cm,radius:Math.max(r,1e8),fixed:true};}
function orbitalVelocity(r,cM){return Math.sqrt(G_SI*params.G_mult*cM/r);}

const PRESETS={
  belt:(n)=>{const c=makeCentralBody();const out=[c];for(let i=0;i<n;i++){const a=(2.0+Math.random()*1.3)*AU;const ecc=0.05+Math.random()*0.20;const theta=(i/n)*2*Math.PI;const vc=orbitalVelocity(a,c.mass);const vPeri=vc*Math.sqrt((1+ecc)/(1-ecc));const mass=1e22+Math.random()*9e22;out.push({id:nextBodyId++,x:a*(1-ecc)*Math.cos(theta),y:a*(1-ecc)*Math.sin(theta),vx:-vPeri*Math.sin(theta),vy:vPeri*Math.cos(theta),mass,radius:Math.cbrt(mass/(2000*4.189))*1.5,fixed:false});}return out;},
  disk:(n)=>{const c=makeCentralBody();c.mass=CENTRAL_MASSES.proto;const out=[c];for(let i=0;i<n;i++){const a=(0.5+Math.random()*1.5)*AU;const ecc=0.10+Math.random()*0.25;const theta=(i/n)*2*Math.PI;const vc=orbitalVelocity(a,c.mass);const vPeri=vc*Math.sqrt((1+ecc)/(1-ecc));const mass=5e23+Math.random()*5e24;out.push({id:nextBodyId++,x:a*(1-ecc)*Math.cos(theta),y:a*(1-ecc)*Math.sin(theta),vx:-vPeri*Math.sin(theta),vy:vPeri*Math.cos(theta),mass,radius:Math.cbrt(mass/(3000*4.189)),fixed:false});}return out;},
  chaos:(n)=>{const c=makeCentralBody();const out=[c];for(let i=0;i<n;i++){const a=(0.8+Math.random()*3.0)*AU;const ecc=0.20+Math.random()*0.50;const theta=Math.random()*2*Math.PI;const vc=orbitalVelocity(a,c.mass);const vPeri=vc*Math.sqrt(Math.min(9,(1+ecc)/Math.max(0.01,1-ecc)));const mass=1e22+Math.random()*1e24;out.push({id:nextBodyId++,x:a*(1-ecc)*Math.cos(theta),y:a*(1-ecc)*Math.sin(theta),vx:-vPeri*Math.sin(theta),vy:vPeri*Math.cos(theta),mass,radius:Math.cbrt(mass/(2000*4.189)),fixed:false});}return out;},
};

function makeOrbitBody(a,theta,cMass,mass,radius){const vc=orbitalVelocity(a,cMass);const ecc=0.05;return{id:nextBodyId++,x:a*Math.cos(theta),y:a*Math.sin(theta),vx:-vc*(1+ecc)*Math.sin(theta),vy:vc*(1+ecc)*Math.cos(theta),mass,radius,fixed:false};}

/* ─────────────────────────────────────────────────────────────────────────────
   RESET HELPER — builds synthetic first frame from initial bodies so
   lastConsumedFrame is valid before the first server response arrives.
───────────────────────────────────────────────────────────────────────────── */
function makeInitialFrame(bodyList){
  let mMax=0,ke=0;
  for(const b of bodyList){if(b.mass>mMax)mMax=b.mass;ke+=0.5*b.mass*(b.vx*b.vx+b.vy*b.vy);}
  return{
    bodies:bodyList.map(b=>[b.id,b.x,b.y,b.vx,b.vy,b.mass,b.radius,b.fixed?1:0]),
    t:0,n:bodyList.length,m_max:mMax,total_ke:ke,total_pe:0
  };
}

/* ─────────────────────────────────────────────────────────────────────────────
   CONTROLS
───────────────────────────────────────────────────────────────────────────── */
function applyPreset(name){
  nextBodyId=0;
  const bodyList=PRESETS[name](params.bodyCount-1);
  simTime=0;buf=[];bufHead=0;simTimeAcc=0;lastRafTs=0;
  nPts.length=0;massPts.length=0;dataLog.length=0;pendingRows=[];tableRowCount=0;
  mergeCount=0;totalKEDiss=0;collisionLog.length=0;
  bodies=bodyList;
  lastConsumedFrame=makeInitialFrame(bodyList);
  autoFitCamera();drawSim();fetchBatch();
}
function hardReset(){applyPreset('belt');}
function spawnBody(){
  const active=bodies.filter(b=>!b.fixed);
  if(active.length>=MAX_BODIES_TRIAL-1){
    const btn=document.getElementById('spawnBtn');
    btn.classList.add('spawn-btn-disabled');btn.textContent='Limit ('+MAX_BODIES_TRIAL+')';
    setTimeout(()=>{btn.classList.remove('spawn-btn-disabled');btn.textContent='+ Body';},1500);
    return;
  }
  const a=(1.5+Math.random()*2)*AU;const theta=Math.random()*2*Math.PI;
  const cMass=bodies.find(b=>b.fixed)?.mass||CENTRAL_MASSES.star;
  const nb=makeOrbitBody(a,theta,cMass,1e19+Math.random()*1e21,2e5+Math.random()*3e5);
  bodies.push(nb);
  /* Update lastConsumedFrame so next fetch includes new body */
  lastConsumedFrame=makeInitialFrame(bodies);
  buf=[];bufHead=0;fetchBatch();
}
function setCentralMass(type){
  params.centralMass=type;
  document.querySelectorAll('.cm-chip').forEach(c=>c.classList.toggle('active',c.dataset.cm===type));
  hardReset();
}
function setTimeScale(idx){
  params.timeScaleIdx=idx;
  document.getElementById('vTS').textContent=TIME_SCALE_LABELS[idx];
  /* Reset wall accumulator so speed change takes effect cleanly */
  simTimeAcc=0;lastRafTs=0;
}
function setGMult(logVal){params.G_mult=Math.pow(10,logVal);document.getElementById('vGm').textContent=params.G_mult.toFixed(2)+'x';buf=[];bufHead=0;fetchBatch();}
function setSoftening(idx){params.softening=SOFTENING_MAP[idx];document.getElementById('vSoft').textContent=(SOFTENING_MAP[idx]/1e3).toFixed(0);buf=[];bufHead=0;fetchBatch();}

/* ─────────────────────────────────────────────────────────────────────────────
   TOUCH / PAN / ZOOM
───────────────────────────────────────────────────────────────────────────── */
const cz=document.getElementById('canvasZone');
function getTouches(e){return Array.from(e.touches).map(t=>{const r=simCanvas.getBoundingClientRect();return{x:t.clientX-r.left,y:t.clientY-r.top};});}
cz.addEventListener('touchstart',e=>{e.preventDefault();const ts=getTouches(e);if(ts.length===1){panStart={sx:ts[0].x,sy:ts[0].y,cx:camX,cy:camY};isDragging=true;cz.classList.add('panning');}else if(ts.length===2){const dx=ts[0].x-ts[1].x,dy=ts[0].y-ts[1].y;pinchDist0=Math.sqrt(dx*dx+dy*dy);isDragging=false;}},{passive:false});
cz.addEventListener('touchmove',e=>{e.preventDefault();const ts=getTouches(e);if(ts.length===1&&isDragging&&panStart){camX=panStart.cx-(ts[0].x-panStart.sx)/camScale;camY=panStart.cy+(ts[0].y-panStart.sy)/camScale;drawSim();}else if(ts.length===2&&pinchDist0!==null){const dx=ts[0].x-ts[1].x,dy=ts[0].y-ts[1].y;const d=Math.sqrt(dx*dx+dy*dy);camScale*=d/pinchDist0;camScale=Math.max(1e-14,Math.min(1e-9,camScale));pinchDist0=d;drawSim();}},{passive:false});
cz.addEventListener('touchend',e=>{e.preventDefault();if(e.touches.length<2)pinchDist0=null;if(e.touches.length===0){isDragging=false;panStart=null;cz.classList.remove('panning');}},{passive:false});
cz.addEventListener('mousedown',e=>{panStart={sx:e.clientX,sy:e.clientY,cx:camX,cy:camY};isDragging=true;cz.classList.add('panning');});
cz.addEventListener('mousemove',e=>{if(!isDragging||!panStart)return;camX=panStart.cx-(e.clientX-panStart.sx)/camScale;camY=panStart.cy+(e.clientY-panStart.sy)/camScale;drawSim();});
cz.addEventListener('mouseup',()=>{isDragging=false;panStart=null;cz.classList.remove('panning');});
cz.addEventListener('mouseleave',()=>{isDragging=false;panStart=null;cz.classList.remove('panning');});
cz.addEventListener('wheel',e=>{e.preventDefault();camScale=Math.max(1e-14,Math.min(1e-9,camScale*(e.deltaY<0?1.1:0.9)));drawSim();},{passive:false});

/* ─────────────────────────────────────────────────────────────────────────────
   SIDE PANEL
───────────────────────────────────────────────────────────────────────────── */
function openPanel(){document.getElementById('sidePanel').classList.add('open');document.getElementById('sideBackdrop').classList.add('open');}
function closePanel(){document.getElementById('sidePanel').classList.remove('open');document.getElementById('sideBackdrop').classList.remove('open');}

/* ─────────────────────────────────────────────────────────────────────────────
   TAB SWITCH
───────────────────────────────────────────────────────────────────────────── */
function switchTab(n){
  activeTab=n;
  const TM={sim:'panelSim',anal:'panelAnal',data:'panelData'};
  const BM={sim:'tabSimBtn',anal:'tabAnalBtn',data:'tabDataBtn'};
  Object.keys(TM).forEach(k=>{document.getElementById(TM[k]).classList.toggle('active',k===n);document.getElementById(BM[k]).classList.toggle('active',k===n);});
  const sb=document.getElementById('settingsBtn');if(sb)sb.style.display=n==='sim'?'':'none';
  if(n!=='sim')closePanel();
  if(n==='anal')requestAnimationFrame(()=>{resizeAnalPlots();requestAnimationFrame(refreshAnalPlots);});
  if(n==='data'){
    tbody.innerHTML='';tableRowCount=0;pendingRows=[];
    const frag=document.createDocumentFragment();
    for(const snap of dataLog){
      for(const b of snap.bodies){
        if(tableRowCount>=MAX_ROWS)break;
        const speed=Math.sqrt(b.vx*b.vx+b.vy*b.vy);
        const tr=document.createElement('tr');
        tr.innerHTML=[snap.t.toFixed(3),snap.n,b.id,b.mass.toExponential(3),b.radius.toExponential(3),(b.x/AU).toFixed(4),(b.y/AU).toFixed(4),speed.toFixed(2)].map(v=>`<td>${v}</td>`).join('');
        frag.appendChild(tr);tableRowCount++;
      }
    }
    tbody.appendChild(frag);
  }
}
function handleUpgrade(e){e.preventDefault();if(window.parent!==window)window.parent.postMessage({type:'upgrade_click',sim:'gravity-asteroid'},'*');else window.location.href='/upgrade';}

/* ─────────────────────────────────────────────────────────────────────────────
   BOOT
───────────────────────────────────────────────────────────────────────────── */
requestAnimationFrame(()=>{
  resizeSim();
  applyPreset('belt');
  requestAnimationFrame(renderLoop);
});
</script>
</body>
</html>
