<?php
declare(strict_types=1);
/**
 * Physics Simulation Engine - Server-Side Rendering
 *
 * Copyright (c) 2025 Akilesh Bhandary
 * All Rights Reserved - Proprietary Code
 *
 * SECURITY NOTE: All physics calculations occur exclusively in this file.
 * The client receives only pre-computed state arrays. Copying the frontend
 * yields no computational capability.
**/

// ── HTTP Headers for JSON API ──────────────────────────────────────────
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Request-Signature');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Parse Request Data Early (Supports JSON & Form Data) ──────────────
$rawInput = file_get_contents('php://input');
$post     = json_decode($rawInput ?: '', true) ?? $_POST;

// ── Request Signature Validation (Prevent Scraping/Replay) ────────────
$rawSig = $_SERVER['HTTP_X_SIG'] ?? '';
$ts     = explode('.', $rawSig)[0] ?? '0';
if (abs(time() - (int)$ts) > 15) {
    http_response_code(403); echo json_encode(['error' => 'Request expired']); exit;
}

// Validate domain & integrator match using the parsed payload
$integrator = $post['integrator'] ?? 'rk4';
$expected   = hash('md5', ($_SERVER['HTTP_HOST'] ?? 'localhost') . $integrator . $ts);
if (substr($rawSig, 14) !== $expected) {
    http_response_code(403); echo json_encode(['error' => 'Invalid signature']); exit;
}

// ═════════════════════════════════════════════════════════════════════
//  BARNES-HUT QUADTREE (O(N log N) force approximation)
//  Used by batchGravityAsteroid.
// ═════════════════════════════════════════════════════════════════════

/**
 * Represents a node in the Barnes-Hut quadtree spatial partitioning structure.
 */
final class QuadNode {
    public float $cx, $cy, $hw;  // centre x, centre y, half-width
    public float $totalMass = 0.0;
    public float $comX = 0.0;    // centre of mass X
    public float $comY = 0.0;    // centre of mass Y
    public ?int  $bodyIdx = null; // leaf body index, null if internal
    
    // FIX: Added properties that were accessed but not defined in your uploaded file.
    public ?float $leafPx = null; 
    public ?float $leafPy = null;
    public ?float $leafM  = null;

    /** @var array<int,QuadNode> children indexed 0..3 (NW,NE,SW,SE) */
    public array $children = [];

    public function __construct(float $cx, float $cy, float $hw) {
        $this->cx = $cx;
        $this->cy = $cy;
        $this->hw = $hw;
    }

    public function isLeaf(): bool {
        return empty($this->children);
    }

    /** Quadrant index for point (px,py) relative to this node's centre. */
    public function quadrant(float $px, float $py): int {
        // 0=NW, 1=NE, 2=SW, 3=SE
        return ($px >= $this->cx ? 1 : 0) | ($py >= $this->cy ? 2 : 0);
    }

    public function childCentre(int $q): array {
        $hw4 = $this->hw * 0.5;
        $dx  = ($q & 1) ? $hw4 : -$hw4;
        $dy  = ($q & 2) ? $hw4 : -$hw4;
        return [$this->cx + $dx, $this->cy + $dy, $hw4];
    }
}

/**
 * Barnes-Hut quadtree for efficient N-body gravitational calculations.
 * Reduces O(N²) pairwise force computation to O(N log N).
 */
final class QuadTree {
    private QuadNode $root;
    private float    $theta; // opening angle criterion

    public function __construct(float $minX, float $minY, float $maxX, float $maxY, float $theta = 0.5) {
        $cx = ($minX + $maxX) * 0.5;
        $cy = ($minY + $maxY) * 0.5;
        $hw = max($maxX - $minX, $maxY - $minY) * 0.5 * 1.01; // slight pad
        $this->root  = new QuadNode($cx, $cy, $hw);
        $this->theta = $theta;
    }

    /** Insert body index $i at position ($px,$py) with mass $m. */
    public function insert(int $i, float $px, float $py, float $m): void {
        $this->insertInto($this->root, $i, $px, $py, $m);
    }

    private function insertInto(QuadNode $node, int $i, float $px, float $py, float $m): void {
        // Update centre-of-mass for this node
        $node->totalMass += $m;
        $node->comX       = ($node->comX * ($node->totalMass - $m) + $px * $m) / $node->totalMass;
        $node->comY       = ($node->comY * ($node->totalMass - $m) + $py * $m) / $node->totalMass;

        if ($node->isLeaf()) {
            if ($node->bodyIdx === null) {
                // Empty leaf — store body here
                $node->bodyIdx = $i;
                // FIX: Store position of the first body so we can find it later during subdivision
                $node->leafPx = $px;
                $node->leafPy = $py;
                $node->leafM  = $m;
                return;
            }

            // Occupied leaf — subdivide
            $existing = $node->bodyIdx;
            $node->bodyIdx = null;

            // Retrieve original position of the existing body
            $exPx = $node->leafPx ?? $node->comX;
            $exPy = $node->leafPy ?? $node->comY;
            $exM  = $node->leafM  ?? ($node->totalMass - $m);

            // Insert existing body into child
            $q = $node->quadrant($exPx, $exPy);
            [$ccx, $ccy, $chw] = $node->childCentre($q);
            if (!isset($node->children[$q])) {
                $child = new QuadNode($ccx, $ccy, $chw);
                $node->children[$q] = $child;
            }
            $this->insertInto($node->children[$q], $existing, $exPx, $exPy, $exM);

            // Insert new body into child
            $q2 = $node->quadrant($px, $py);
            [$ccx2, $ccy2, $chw2] = $node->childCentre($q2);
            if (!isset($node->children[$q2])) {
                $child = new QuadNode($ccx2, $ccy2, $chw2);
                $node->children[$q2] = $child;
            }
            $this->insertInto($node->children[$q2], $i, $px, $py, $m);
            return;
        }

        // Internal node — descend
        $q = $node->quadrant($px, $py);
        [$ccx, $ccy, $chw] = $node->childCentre($q);
        if (!isset($node->children[$q])) {
            $node->children[$q] = new QuadNode($ccx, $ccy, $chw);
        }
        $this->insertInto($node->children[$q], $i, $px, $py, $m);
    }

    /** Compute gravitational acceleration on body $i at ($px,$py). */
    public function accel(int $i, float $px, float $py, float $G, float $softening): array {
        return $this->accelNode($this->root, $i, $px, $py, $G, $softening);
    }

    private function accelNode(QuadNode $node, int $i, float $px, float $py, float $G, float $softening): array {
        if ($node->totalMass === 0.0) return [0.0, 0.0];

        $dx = $node->comX - $px;
        $dy = $node->comY - $py;
        $r2 = $dx * $dx + $dy * $dy + $softening * $softening;

        if ($node->isLeaf()) {
            if ($node->bodyIdx === $i) return [0.0, 0.0]; // self
            $r3 = $r2 * sqrt($r2);
            $f  = $G * $node->totalMass / $r3;
            return [$f * $dx, $f * $dy];
        }

        // Barnes-Hut criterion: if node is far enough, treat as point mass
        $r = sqrt($r2);
        if ($r > 0.0 && (2.0 * $node->hw / $r) < $this->theta) {
            $r3 = $r2 * $r;
            $f  = $G * $node->totalMass / $r3;
            return [$f * $dx, $f * $dy];
        }

        // Recurse into children
        $ax = 0.0; $ay = 0.0;
        foreach ($node->children as $child) {
            [$cax, $cay] = $this->accelNode($child, $i, $px, $py, $G, $softening);
            $ax += $cax;
            $ay += $cay;
        }
        return [$ax, $ay];
    }
}

// ═════════════════════════════════════════════════════════════════════
//  SIMULATION ENGINE
// ═════════════════════════════════════════════════════════════════════
class SimulationEngine {
    private static function getFingerprint(): string {
        return hash('sha256', ($_SERVER['HTTP_HOST'] ?? '') . __DIR__ . 'akilesh-physics');
    }

    // ─── BATCH ROUTER ────────────────────────────────────────────────────────
    public static function batchCompute(string $type, array $params, int $steps = 120, string $integrator = 'rk4'): array {
        $steps = min(max(1, $steps), 600);
        error_log(sprintf(
            '[BATCH] type:%s steps:%d integ:%s fp:%s',
            $type, $steps, $integrator, self::getFingerprint()
        ));

        switch ($type) {
            case 'pendulum':
                return self::batchPendulum($params, $steps, $integrator);
            case 'double-pendulum':
                return self::batchDoublePendulum($params, $steps, $integrator);
            case 'gravity-asteroid':
                return self::batchGravityAsteroid($params, $steps);
            default:
                throw new \InvalidArgumentException("Unknown simulation type: $type");
        }
    }

    // ─── GRAVITY-ASTEROID BATCH (PRESERVED EXACTLY AS PROVIDED) ────────
    /**
     * N-body gravitational simulation.
     * Integrator: Velocity-Verlet (leapfrog), O(N^2) direct pairwise forces.
     * Collisions: inelastic merge (trial) or SPH fragmentation (paid).
     */
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
            'finalBodies' => self::serializeBodies(
                $bId, $bX, $bY, $bVx, $bVy, $bMass, $bRadius, $bFixed, $bActive
            ),
        ];
    }

    private static function serializeBodies(
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

    // ─── PENDULUM BATCH ──────────────────────────────────────────────────────
    private static function batchPendulum(array $p, int $steps, string $integrator): array {
        $theta   = (float)($p['theta']     ?? 0);
        $omega   = (float)($p['omega']     ?? 0);
        $time    = (float)($p['time']      ?? 0);
        $L       = (float)($p['length']    ?? 1);
        $g       = (float)($p['gravity']   ?? 9.81);
        $b       = (float)($p['damping']   ?? 0.1);
        $dAmp    = (float)($p['driveAmp'] ?? $p['driveAmplitude'] ?? 0);
        $dFreq   = (float)($p['driveFreq'] ?? $p['driveFrequency'] ?? 0.667);
        $dt      = (float)($p['dt']        ?? 0.016);

        $H0 = self::hamiltonianPendulum($theta, $omega, $L, $g);
        $states = [];
        for ($i = 0; $i < $steps; $i++) {
            switch ($integrator) {
                case 'symplectic':
                    [$theta, $omega] = self::symplecticPendulum(
                        $theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq
                    );
                    break;
                case 'euler':
                    [$theta, $omega] = self::eulerPendulum(
                        $theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq
                    );
                    break;
                default:
                    [$theta, $omega] = self::rk4Pendulum(
                        $theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq
                    );
            }
            $time += $dt;
            $ke     = 0.5 * $L * $L * $omega * $omega;
            $pe     = $g * $L * (1.0 - cos($theta));
            $hdrift = ($ke + $pe) - $H0;
            $states[] = [
                round($theta,  7),
                round($omega,  7),
                round($time,   5),
                round(sin($theta) * $L, 6),
                round(cos($theta) * $L, 6),
                round($ke,     6),
                round($pe,     6),
                round($hdrift, 8),
            ];
        }
        return [
            'states'     => $states,
            'finalState' => ['theta' => $theta, 'omega' => $omega, 'time'  => $time],
        ];
    }

    // ─── DOUBLE PENDULUM BATCH ───────────────────────────────────────────────
    private static function batchDoublePendulum(array $p, int $steps, string $integrator): array {
        $t1   = (float)($p['theta1'] ?? $p['t1'] ?? 1.0);
        $t2   = (float)($p['theta2'] ?? $p['t2'] ?? 1.0);
        $o1   = (float)($p['omega1'] ?? $p['o1'] ?? 0.0);
        $o2   = (float)($p['omega2'] ?? $p['o2'] ?? 0.0);
        $L1   = (float)($p['length1'] ?? $p['L1'] ?? 1.0);
        $L2   = (float)($p['length2'] ?? $p['L2'] ?? 1.0);
        $m1   = (float)($p['mass1']   ?? $p['m1'] ?? 1.0);
        $m2   = (float)($p['mass2']   ?? $p['m2'] ?? 1.0);
        $g    = (float)($p['gravity'] ?? 9.81);
        $b    = (float)($p['damping'] ?? 0.0);
        $dt   = (float)($p['dt']      ?? 0.016);
        $time = (float)($p['time']    ?? 0.0);

        $H0 = self::hamiltonianDP($t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g);
        $states = [];
        for ($i = 0; $i < $steps; $i++) {
            switch ($integrator) {
                case 'symplectic':
                    [$t1, $t2, $o1, $o2] = self::symplecticDP(
                        $t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g, $dt
                    );
                    break;
                case 'euler':
                    [$t1, $t2, $o1, $o2] = self::eulerDP(
                        $t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g, $dt
                    );
                    break;
                default:
                    [$t1, $t2, $o1, $o2] = self::rk4DoublePendulum(
                        $t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g, $dt
                    );
            }
            if ($b > 0.0) {
                $damp = 1.0 - $b * $dt;
                $o1  *= $damp;
                $o2  *= $damp;
            }
            $time += $dt;
            $cos_d  = cos($t1 - $t2);
            $ke = 0.5 * $m1 * $L1 * $L1 * $o1 * $o1
                + 0.5 * $m2 * (
                    $L1 * $L1 * $o1 * $o1
                    + $L2 * $L2 * $o2 * $o2
                    + 2.0 * $L1 * $L2 * $o1 * $o2 * $cos_d
                );
            $pe = -($m1 + $m2) * $g * $L1 * cos($t1)
                - $m2 * $g * $L2 * cos($t2);
            $hdrift = ($ke + $pe) - $H0;
            $states[] = [
                round($t1,     7), round($t2,     7),
                round($o1,     7), round($o2,     7),
                round($time,   5), round($ke,     6),
                round($pe,     6), round($hdrift, 8),
            ];
        }
        return [
            'states'     => $states,
            'finalState' => [
                'theta1' => $t1, 'theta2' => $t2,
                'omega1' => $o1, 'omega2' => $o2,
                'time'   => $time,
            ],
        ];
    }

    // ─── HAMILTONIAN HELPERS ─────────────────────────────────────────────────
    private static function hamiltonianDP(
        float $t1, float $t2, float $o1, float $o2,
        float $L1, float $L2, float $m1, float $m2, float $g
    ): float {
        $cos_d = cos($t1 - $t2);
        $ke = 0.5 * $m1 * $L1 * $L1 * $o1 * $o1
            + 0.5 * $m2 * (
                $L1 * $L1 * $o1 * $o1
                + $L2 * $L2 * $o2 * $o2
                + 2.0 * $L1 * $L2 * $o1 * $o2 * $cos_d
            );
        $pe = -($m1 + $m2) * $g * $L1 * cos($t1)
            - $m2 * $g * $L2 * cos($t2);
        return $ke + $pe;
    }

    private static function hamiltonianPendulum(
        float $theta, float $omega, float $L, float $g
    ): float {
        return 0.5 * $L * $L * $omega * $omega
            + $g * $L * (1.0 - cos($theta));
    }

    // ─── INTEGRATORS ─────────────────────────────────────────────────────────
    private static function dpAccel(
        float $t1, float $t2, float $o1, float $o2,
        float $L1, float $L2, float $m1, float $m2, float $g
    ): array {
        $dtheta = $t1 - $t2;
        $cos_d  = cos($dtheta);
        $sin_d  = sin($dtheta);
        $den1   = ($m1 + $m2) * $L1 - $m2 * $L1 * $cos_d * $cos_d;
        $den2   = ($L2 / $L1) * $den1;
        $a1 = (
            $m2 * $L1 * $o1 * $o1 * $sin_d * $cos_d
            + $m2 * $g  * sin($t2) * $cos_d
            + $m2 * $L2 * $o2 * $o2 * $sin_d
            - ($m1 + $m2) * $g * sin($t1)
        ) / $den1;
        $a2 = (
            - $m2 * $L2 * $o2 * $o2 * $sin_d * $cos_d
            + ($m1 + $m2) * (
                $g * sin($t1) * $cos_d
                - $L1 * $o1 * $o1 * $sin_d
                - $g * sin($t2)
            )
        ) / $den2;
        return [$a1, $a2];
    }

    private static function symplecticDP(
        float $t1, float $t2, float $o1, float $o2,
        float $L1, float $L2, float $m1, float $m2,
        float $g,  float $dt
    ): array {
        [$a1h, $a2h] = self::dpAccel($t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g);
        $o1h = $o1 + 0.5 * $dt * $a1h;
        $o2h = $o2 + 0.5 * $dt * $a2h;
        $t1n = $t1 + $dt * $o1h;
        $t2n = $t2 + $dt * $o2h;
        [$a1n, $a2n] = self::dpAccel($t1n, $t2n, $o1h, $o2h, $L1, $L2, $m1, $m2, $g);
        return [$t1n, $t2n, $o1h + 0.5 * $dt * $a1n, $o2h + 0.5 * $dt * $a2n];
    }

    private static function eulerDP(
        float $t1, float $t2, float $o1, float $o2,
        float $L1, float $L2, float $m1, float $m2,
        float $g,  float $dt
    ): array {
        [$a1, $a2] = self::dpAccel($t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g);
        return [$t1 + $dt * $o1, $t2 + $dt * $o2, $o1 + $dt * $a1, $o2 + $dt * $a2];
    }

    private static function rk4DoublePendulum(
        float $t1, float $t2, float $o1, float $o2,
        float $L1, float $L2, float $m1, float $m2,
        float $g,  float $dt
    ): array {
        $deriv = static function ($t1, $t2, $o1, $o2)
            use ($L1, $L2, $m1, $m2, $g): array {
            $dtheta = $t1 - $t2;
            $den1 = ($m1 + $m2) * $L1 - $m2 * $L1 * cos($dtheta) * cos($dtheta);
            $den2 = ($L2 / $L1) * $den1;
            $a1 = (
                $m2 * $L1 * $o1 * $o1 * sin($dtheta) * cos($dtheta)
                + $m2 * $g  * sin($t2) * cos($dtheta)
                + $m2 * $L2 * $o2 * $o2 * sin($dtheta)
                - ($m1 + $m2) * $g * sin($t1)
            ) / $den1;
            $a2 = (
                - $m2 * $L2 * $o2 * $o2 * sin($dtheta) * cos($dtheta)
                + ($m1 + $m2) * (
                    $g * sin($t1) * cos($dtheta)
                    - $L1 * $o1 * $o1 * sin($dtheta)
                    - $g * sin($t2)
                )
            ) / $den2;
            return [$o1, $o2, $a1, $a2];
        };
        [$k1t1,$k1t2,$k1o1,$k1o2] = $deriv($t1,$t2,$o1,$o2);
        [$k2t1,$k2t2,$k2o1,$k2o2] = $deriv($t1+0.5*$dt*$k1t1,$t2+0.5*$dt*$k1t2,$o1+0.5*$dt*$k1o1,$o2+0.5*$dt*$k1o2);
        [$k3t1,$k3t2,$k3o1,$k3o2] = $deriv($t1+0.5*$dt*$k2t1,$t2+0.5*$dt*$k2t2,$o1+0.5*$dt*$k2o1,$o2+0.5*$dt*$k2o2);
        [$k4t1,$k4t2,$k4o1,$k4o2] = $deriv($t1+$dt*$k3t1,$t2+$dt*$k3t2,$o1+$dt*$k3o1,$o2+$dt*$k3o2);
        return [
            $t1 + $dt/6*($k1t1+2*$k2t1+2*$k3t1+$k4t1),
            $t2 + $dt/6*($k1t2+2*$k2t2+2*$k3t2+$k4t2),
            $o1 + $dt/6*($k1o1+2*$k2o1+2*$k3o1+$k4o1),
            $o2 + $dt/6*($k1o2+2*$k2o2+2*$k3o2+$k4o2),
        ];
    }

    private static function rk4Pendulum(
        float $th, float $om, float $t, float $dt,
        float $L,  float $g, float $b,
        float $dAmp, float $dFreq
    ): array {
        $deriv = static function (float $th, float $om, float $t)
            use ($L, $g, $b, $dAmp, $dFreq): array {
            return [$om, -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t)];
        };
        [$k1t, $k1o] = $deriv($th, $om, $t);
        [$k2t, $k2o] = $deriv($th + 0.5*$dt*$k1t, $om + 0.5*$dt*$k1o, $t + 0.5*$dt);
        [$k3t, $k3o] = $deriv($th + 0.5*$dt*$k2t, $om + 0.5*$dt*$k2o, $t + 0.5*$dt);
        [$k4t, $k4o] = $deriv($th + $dt*$k3t,      $om + $dt*$k3o,     $t + $dt);
        return [
            $th + ($dt / 6.0) * ($k1t + 2*$k2t + 2*$k3t + $k4t),
            $om + ($dt / 6.0) * ($k1o + 2*$k2o + 2*$k3o + $k4o),
        ];
    }

    private static function symplecticPendulum(
        float $th, float $om, float $t, float $dt,
        float $L,  float $g, float $b,
        float $dAmp, float $dFreq
    ): array {
        $alpha_half = -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t);
        $om_half    = $om + 0.5 * $dt * $alpha_half;
        $th_new     = $th + $dt * $om_half;
        $alpha_new  = -($g / $L) * sin($th_new) - $b * $om_half + $dAmp * cos($dFreq * ($t + $dt));
        $om_new     = $om_half + 0.5 * $dt * $alpha_new;
        return [$th_new, $om_new];
    }

    private static function eulerPendulum(
        float $th, float $om, float $t, float $dt,
        float $L,  float $g, float $b,
        float $dAmp, float $dFreq
    ): array {
        $alpha = -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t);
        return [$th + $dt * $om, $om + $dt * $alpha];
    }

    // ─── LEGACY SINGLE-STEP METHODS ──────────────────────────────────────────
    public static function calculatePendulum(array $params): array {
        $theta    = (float)($params['theta']    ?? 0);
        $omega    = (float)($params['omega']    ?? 0);
        $time     = (float)($params['time']     ?? 0);
        $length   = (float)($params['length']   ?? 1);
        $gravity  = (float)($params['gravity']  ?? 9.81);
        $damping  = (float)($params['damping']  ?? 0.1);
        $driveAmp = (float)($params['driveAmp'] ?? $params['driveAmplitude'] ?? 0);
        $driveFreq= (float)($params['driveFreq'] ?? $params['driveFrequency'] ?? 0.667);
        $dt       = (float)($params['dt']       ?? 0.016);
        
        [$newTheta, $newOmega] = self::rk4Pendulum(
            $theta, $omega, $time, $dt, $length, $gravity, $damping, $driveAmp, $driveFreq
        );
        $v  = abs($omega) * $length;
        $h  = $length * (1 - cos($theta));
        return [
            'theta' => $newTheta, 'omega' => $newOmega,
            'ke'    => 0.5 * $v * $v, 'pe'    => $gravity * $h,
            'time'  => $time + $dt,
            'x'     => sin($newTheta) * $length,
            'y'     => cos($newTheta) * $length,
        ];
    }

    public static function calculateDoublePendulum(array $params): array {
        $t1 = (float)($params['theta1'] ?? $params['t1'] ?? 1);
        $t2 = (float)($params['theta2'] ?? $params['t2'] ?? 1);
        $o1 = (float)($params['omega1'] ?? $params['o1'] ?? 0);
        $o2 = (float)($params['omega2'] ?? $params['o2'] ?? 0);
        $L1 = (float)($params['length1'] ?? $params['L1'] ?? 1);
        $L2 = (float)($params['length2'] ?? $params['L2'] ?? 1);
        $m1 = (float)($params['mass1']   ?? $params['m1'] ?? 1);
        $m2 = (float)($params['mass2']   ?? $params['m2'] ?? 1);
        $g  = (float)($params['gravity'] ?? 9.81);
        $dt = (float)($params['dt']      ?? 0.016);
        
        [$newT1, $newT2, $newO1, $newO2] = self::rk4DoublePendulum(
            $t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g, $dt
        );
        return [
            'theta1' => $newT1, 'theta2' => $newT2,
            'omega1' => $newO1, 'omega2' => $newO2,
            'x1'     => $L1 * sin($newT1),
            'y1'     => -$L1 * cos($newT1),
            'x2'     => $L1 * sin($newT1) + $L2 * sin($newT2),
            'y2'     => -$L1 * cos($newT1) - $L2 * cos($newT2),
        ];
    }

    public static function calculateProjectile(array $params): array {
        $x    = (float)($params['x']       ?? 0);
        $y    = (float)($params['y']       ?? 0);
        $vx   = (float)($params['vx']      ?? 10);
        $vy   = (float)($params['vy']      ?? 10);
        $g    = (float)($params['gravity'] ?? 9.81);
        $drag = (float)($params['drag']    ?? 0);
        $dt   = (float)($params['dt']      ?? 0.016);
        $speed    = sqrt($vx*$vx + $vy*$vy);
        $newVx    = $vx + (-$drag * $speed * $vx) * $dt;
        $newVy    = $vy + (-$drag * $speed * $vy - $g) * $dt;
        return [
            'x'     => $x + $newVx * $dt,
            'y'     => $y + $newVy * $dt,
            'vx'    => $newVx,
            'vy'    => $newVy,
            'speed' => sqrt($newVx*$newVx + $newVy*$newVy),
        ];
    }

    public static function calculateOrbital(array $params): array {
        $x    = (float)($params['x']            ?? 100);
        $y    = (float)($params['y']            ?? 0);
        $vx   = (float)($params['vx']           ?? 0);
        $vy   = (float)($params['vy']           ?? 10);
        $cM   = (float)($params['centralMass']  ?? 1000);
        $G    = (float)($params['G']            ?? 1);
        $dt   = (float)($params['dt']           ?? 0.016);
        $r     = max(0.1, sqrt($x*$x + $y*$y));
        $force = $G * $cM / ($r * $r);
        $newVx = $vx - $force * ($x/$r) * $dt;
        $newVy = $vy - $force * ($y/$r) * $dt;
        return [
            'x'        => $x + $newVx * $dt,
            'y'        => $y + $newVy * $dt,
            'vx'       => $newVx,
            'vy'       => $newVy,
            'distance' => sqrt(($x+$newVx*$dt)**2 + ($y+$newVy*$dt)**2),
        ];
    }

    public static function calculateParticleFluid(array $params): array {
        $y               = (float)($params['y']               ?? 0);
        $vy              = (float)($params['vy']              ?? 0);
        $mass            = (float)($params['mass']            ?? 1);
        $radius          = (float)($params['radius']          ?? 0.1);
        $fluidDensity    = (float)($params['fluidDensity']    ?? 1000);
        $particleDensity = (float)($params['particleDensity'] ?? 500);
        $g               = (float)($params['gravity']         ?? 9.81);
        $dt              = (float)($params['dt']              ?? 0.016);
        $volume    = (4.0/3.0) * M_PI * $radius ** 3;
        $buoyancy  = $fluidDensity    * $volume * $g;
        $weight    = $particleDensity * $volume * $g;
        $drag      = -6 * M_PI * $radius * 0.001 * $vy;
        $acc       = ($buoyancy - $weight + $drag) / $mass;
        return [
            'y'        => $y  + ($vy + $acc*$dt) * $dt,
            'vy'       => $vy + $acc * $dt,
            'buoyancy' => $buoyancy,
            'weight'   => $weight,
            'drag'     => abs($drag),
        ];
    }

    // ─── MAIN REQUEST ROUTER ─────────────────────────────────────────────────
    public static function calculate(string $type, array $params): array {
        error_log(sprintf(
            '[SIMULATION] Type:%s IP:%s Fingerprint:%s',
            $type, $_SERVER['REMOTE_ADDR'] ?? 'unknown', self::getFingerprint()
        ));
        switch ($type) {
            case 'pendulum':       return self::calculatePendulum($params);
            case 'double-pendulum':return self::calculateDoublePendulum($params);
            case 'projectile':     return self::calculateProjectile($params);
            case 'orbital':        return self::calculateOrbital($params);
            case 'particle-fluid':
            case 'buoyancy':       return self::calculateParticleFluid($params);
            default:
                throw new \InvalidArgumentException("Unknown simulation type: $type");
        }
    }
}

// ─── HTTP ENTRY POINT ────────────────────────────────────────────────────────
try {
    $action = $post['action'] ?? 'single';
    $type   = $post['type']   ?? '';
    if (empty($type)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing simulation type']);
        exit;
    }
    if ($action === 'batch') {
        $steps      = (int)   ($post['steps']      ?? 120);
        $integrator = (string)($post['integrator'] ?? 'rk4');
        $result     = SimulationEngine::batchCompute($type, $post, $steps, $integrator);
    } else {
        $result = SimulationEngine::calculate($type, $post);
    }
    echo json_encode($result);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}