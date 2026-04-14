<?php
declare(strict_types=1);

/**
 * Physics Simulation Engine - Server-Side Rendering
 * Copyright (c) 2025 Akilesh Bhandary
 * All Rights Reserved - Proprietary Code
 *
 * This code contains proprietary physics algorithms.
 * Unauthorized copying, distribution, or reverse engineering prohibited.
 */

// ─── CORS & JSON headers (iframe-friendly) ───────────────────────────────────
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ═══════════════════════════════════════════════════════════════════════════════
//  BARNES-HUT QUADTREE  (O(N log N) force approximation)
//  Used by batchGravityAsteroid.
//  theta = 0.5 — standard accuracy/speed balance for N-body on mobile.
// ═══════════════════════════════════════════════════════════════════════════════

final class QuadNode {
    public float $cx, $cy, $hw;  // centre x, centre y, half-width
    public float $totalMass = 0.0;
    public float $comX = 0.0;    // centre of mass X
    public float $comY = 0.0;    // centre of mass Y
    public ?int  $bodyIdx = null; // leaf body index, null if internal
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
        return ($px >= $this->cx ? 1 : 0) | ($py >= $this->cy ? 2 : 0);
        // 0=NW, 1=NE, 2=SW, 3=SE
    }

    public function childCentre(int $q): array {
        $hw4 = $this->hw * 0.5;
        $dx  = ($q & 1) ? $hw4 : -$hw4;
        $dy  = ($q & 2) ? $hw4 : -$hw4;
        return [$this->cx + $dx, $this->cy + $dy, $hw4];
    }
}

final class QuadTree {
    private QuadNode $root;
    private float    $theta; // opening angle criterion

    public function __construct(
        float $minX, float $minY, float $maxX, float $maxY,
        float $theta = 0.5
    ) {
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

    private function insertInto(
        QuadNode $node, int $i, float $px, float $py, float $m
    ): void {
        // Update centre-of-mass for this node
        $node->totalMass += $m;
        $node->comX       = ($node->comX * ($node->totalMass - $m) + $px * $m)
                           / $node->totalMass;
        $node->comY       = ($node->comY * ($node->totalMass - $m) + $py * $m)
                           / $node->totalMass;

        if ($node->isLeaf()) {
            if ($node->bodyIdx === null) {
                // Empty leaf — store body here
                $node->bodyIdx = $i;
                return;
            }
            // Occupied leaf — subdivide
            $existing = $node->bodyIdx;
            $node->bodyIdx = null;
            // Re-insert existing body into a child
            // (we pass its position via the node's comX/comY snapshot — but we
            //  need original positions, so callers must pass them; here we rely
            //  on the fact that a just-created leaf has comX/comY == its single
            //  body's position before this insert modified them.)
            // To keep the implementation self-contained without a position
            // lookup, we store positions on creation and reuse them below.
            // This is handled by deferring existing re-insert to after children
            // are created using the node's pre-update com snapshot.
            // Simpler approach: store pos on the node for leaf only.
            //
            // Revised: track leafPx/leafPy for single-body leaves.
            [$exCx, $exCy, $exHw] = $node->childCentre(0); // temp
            unset($exCx, $exCy, $exHw);
            // Fall through to the general subdivide path below.
            // We need the original position of the existing body — stored in
            // node->leafPx / node->leafPy (set when body was first inserted).
            $exPx = $node->leafPx ?? $node->comX;
            $exPy = $node->leafPy ?? $node->comY;
            $exM  = $node->leafM  ?? ($node->totalMass - $m);

            $q = $node->quadrant($exPx, $exPy);
            [$ccx, $ccy, $chw] = $node->childCentre($q);
            if (!isset($node->children[$q])) {
                $node->children[$q] = new QuadNode($ccx, $ccy, $chw);
            }
            $this->insertInto($node->children[$q], $existing, $exPx, $exPy, $exM);

            // Now insert the new body
            $q2 = $node->quadrant($px, $py);
            [$ccx2, $ccy2, $chw2] = $node->childCentre($q2);
            if (!isset($node->children[$q2])) {
                $node->children[$q2] = new QuadNode($ccx2, $ccy2, $chw2);
            }
            $this->insertInto($node->children[$q2], $i, $px, $py, $m);
            return;
        }

        // Internal node — descend
        $q = $node->quadrant($px, $py);
        [$ccx, $ccy, $chw] = $node->childCentre($q);
        if (!isset($node->children[$q])) {
            $child = new QuadNode($ccx, $ccy, $chw);
            // Mark leaf position for future subdivision
            $child->leafPx = $px;
            $child->leafPy = $py;
            $child->leafM  = $m;
            $node->children[$q] = $child;
        }
        $this->insertInto($node->children[$q], $i, $px, $py, $m);
    }

    /**
     * Compute gravitational acceleration on body $i at ($px,$py).
     * Uses Barnes-Hut opening-angle criterion.
     * Returns [ax, ay].
     */
    public function accel(
        int $i, float $px, float $py, float $G, float $softening
    ): array {
        return $this->accelNode($this->root, $i, $px, $py, $G, $softening);
    }

    private function accelNode(
        QuadNode $node, int $i, float $px, float $py,
        float $G, float $softening
    ): array {
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

// ═══════════════════════════════════════════════════════════════════════════════
//  SIMULATION ENGINE
// ═══════════════════════════════════════════════════════════════════════════════

class SimulationEngine {

    private static function getFingerprint(): string {
        return hash('sha256', ($_SERVER['HTTP_HOST'] ?? '') . __DIR__ . 'akilesh-physics');
    }

    // ─── BATCH ROUTER ────────────────────────────────────────────────────────
    public static function batchCompute(
        string $type,
        array  $params,
        int    $steps = 120,
        string $integrator = 'rk4'
    ): array {
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

    // ─── GRAVITY-ASTEROID BATCH ──────────────────────────────────────────────
    /**
     * N-body gravitational simulation with Barnes-Hut quadtree (theta=0.5),
     * Verlet (leapfrog) integration, inelastic merging, and SPH-style
     * fragmentation.
     *
     * Input params:
     *   bodies        JSON array: [{id,x,y,vx,vy,mass,radius,fixed},...}]
     *   G             gravitational constant (simulation units)
     *   softening     softening length to avoid singularity
     *   dt            timestep (seconds, already scaled by client time-scale)
     *   steps         frames to compute
     *   frag_threshold collision energy ratio above which fragmentation occurs
     *                  0 = merges only (trial), >0 = fragmentation (paid)
     *   max_bodies    hard cap on body count per batch (200 for paid, 3 for trial)
     *
     * Output per frame:
     *   states: array of body arrays — each body: [id,x,y,vx,vy,mass,radius,active]
     *   collisions: [{t,type,id_a,id_b,ke_diss,result_mass}]
     *   stats: {n, m_max, total_ke, total_pe, sim_time}
     */
    private static function batchGravityAsteroid(
        array $params,
        int   $steps
    ): array {
        // ── Decode bodies ────────────────────────────────────────────────────
        $bodiesRaw = $params['bodies'] ?? '[]';
        if (is_string($bodiesRaw)) {
            $bodiesRaw = json_decode($bodiesRaw, true) ?? [];
        }

        $G              = (float)($params['G']              ?? 6.674e-11);
        $softening      = (float)($params['softening']      ?? 1e6);
        $dt             = (float)($params['dt']             ?? 3600.0);
        $fragThreshold  = (float)($params['frag_threshold'] ?? 0.0);
        $maxBodies      = (int)  ($params['max_bodies']     ?? 200);
        $simTime        = (float)($params['sim_time']       ?? 0.0);

        $maxBodies = min(max(3, $maxBodies), 200);

        // ── Body state arrays (flat parallel arrays — faster than objects) ──
        $id     = [];
        $x      = [];
        $y      = [];
        $vx     = [];
        $vy     = [];
        $mass   = [];
        $radius = [];
        $fixed  = []; // bool — central mass is fixed
        $active = []; // bool — false = merged/ejected, skip
        $nextId = 0;

        foreach ($bodiesRaw as $b) {
            $id[]     = (int)  ($b['id']     ?? $nextId);
            $x[]      = (float)($b['x']      ?? 0.0);
            $y[]      = (float)($b['y']      ?? 0.0);
            $vx[]     = (float)($b['vx']     ?? 0.0);
            $vy[]     = (float)($b['vy']     ?? 0.0);
            $mass[]   = (float)($b['mass']   ?? 1e15);
            $radius[] = (float)($b['radius'] ?? 1e4);
            $fixed[]  = (bool) ($b['fixed']  ?? false);
            $active[] = true;
            $nextId   = max($nextId, (int)($b['id'] ?? 0)) + 1;
        }

        $n = count($id);

        // ── Output accumulators ──────────────────────────────────────────────
        $frameStates = [];
        $collisions  = [];

        // ── Compute bounding box for quadtree ───────────────────────────────
        $bbPad   = 2.0; // padding multiplier
        $getAABB = static function () use (&$x, &$y, &$active, $bbPad): array {
            $minX = PHP_FLOAT_MAX; $maxX = -PHP_FLOAT_MAX;
            $minY = PHP_FLOAT_MAX; $maxY = -PHP_FLOAT_MAX;
            foreach ($x as $i => $xi) {
                if (!$active[$i]) continue;
                if ($xi < $minX) $minX = $xi;
                if ($xi > $maxX) $maxX = $xi;
                if ($y[$i] < $minY) $minY = $y[$i];
                if ($y[$i] > $maxY) $maxY = $y[$i];
            }
            if ($minX === PHP_FLOAT_MAX) return [-1e9, -1e9, 1e9, 1e9];
            $pad = max(1e6, ($maxX - $minX + $maxY - $minY) * 0.5 * ($bbPad - 1.0));
            return [$minX - $pad, $minY - $pad, $maxX + $pad, $maxY + $pad];
        };

        // ── Velocity-Verlet (leapfrog) integration ───────────────────────────
        // We keep half-step velocities for leapfrog continuity across batches.
        // On the first call the client passes vx/vy as full-step; we treat
        // them as half-step to stay simple — the error is O(dt) once, then
        // the integrator is O(dt^2) per step thereafter.

        for ($step = 0; $step < $steps; $step++) {

            // Count active bodies
            $nActive = 0;
            foreach ($active as $a) { if ($a) $nActive++; }
            if ($nActive < 2) break;

            // Build quadtree
            [$bbMinX, $bbMinY, $bbMaxX, $bbMaxY] = $getAABB();
            $qt = new QuadTree($bbMinX, $bbMinY, $bbMaxX, $bbMaxY, 0.5);
            foreach ($x as $i => $xi) {
                if ($active[$i]) $qt->insert($i, $xi, $y[$i], $mass[$i]);
            }

            // Compute accelerations and integrate (leapfrog velocity step 1)
            $ax = array_fill(0, $n, 0.0);
            $ay = array_fill(0, $n, 0.0);
            foreach ($x as $i => $xi) {
                if (!$active[$i] || $fixed[$i]) continue;
                [$ax[$i], $ay[$i]] = $qt->accel($i, $xi, $y[$i], $G, $softening);
            }

            // Update positions (x = x + v*dt + 0.5*a*dt^2)
            foreach ($x as $i => $xi) {
                if (!$active[$i] || $fixed[$i]) continue;
                $x[$i] += $vx[$i] * $dt + 0.5 * $ax[$i] * $dt * $dt;
                $y[$i] += $vy[$i] * $dt + 0.5 * $ay[$i] * $dt * $dt;
            }

            // Rebuild quadtree with updated positions
            [$bbMinX, $bbMinY, $bbMaxX, $bbMaxY] = $getAABB();
            $qt2 = new QuadTree($bbMinX, $bbMinY, $bbMaxX, $bbMaxY, 0.5);
            foreach ($x as $i => $xi) {
                if ($active[$i]) $qt2->insert($i, $xi, $y[$i], $mass[$i]);
            }

            // Compute new accelerations for velocity update (leapfrog step 2)
            $ax2 = array_fill(0, $n, 0.0);
            $ay2 = array_fill(0, $n, 0.0);
            foreach ($x as $i => $xi) {
                if (!$active[$i] || $fixed[$i]) continue;
                [$ax2[$i], $ay2[$i]] = $qt2->accel($i, $xi, $y[$i], $G, $softening);
            }

            // Update velocities (v = v + 0.5*(a_old + a_new)*dt)
            foreach ($vx as $i => $_) {
                if (!$active[$i] || $fixed[$i]) continue;
                $vx[$i] += 0.5 * ($ax[$i] + $ax2[$i]) * $dt;
                $vy[$i] += 0.5 * ($ay[$i] + $ay2[$i]) * $dt;
            }

            $simTime += $dt;

            // ── Collision detection ──────────────────────────────────────────
            // O(N^2) pair check — acceptable for N <= 200
            // Sorted by mass descending so merges favour the heavier body
            $activeIdx = [];
            foreach ($active as $i => $a) { if ($a) $activeIdx[] = $i; }
            usort($activeIdx, static fn($a, $b) => $mass[$b] <=> $mass[$a]);

            $merged = []; // indices to deactivate this step

            $ni = count($activeIdx);
            for ($ai = 0; $ai < $ni; $ai++) {
                $i = $activeIdx[$ai];
                if (in_array($i, $merged, true)) continue;

                for ($aj = $ai + 1; $aj < $ni; $aj++) {
                    $j = $activeIdx[$aj];
                    if (in_array($j, $merged, true)) continue;

                    $dx  = $x[$i] - $x[$j];
                    $dy  = $y[$i] - $y[$j];
                    $r   = sqrt($dx * $dx + $dy * $dy);
                    $rij = $radius[$i] + $radius[$j];

                    if ($r > $rij) continue;

                    // Relative velocity
                    $dvx   = $vx[$i] - $vx[$j];
                    $dvy   = $vy[$i] - $vy[$j];
                    $relKE = 0.5 * ($mass[$i] * $mass[$j] / ($mass[$i] + $mass[$j]))
                           * ($dvx * $dvx + $dvy * $dvy);

                    $totalM = $mass[$i] + $mass[$j];
                    // Specific impact energy
                    $qD = ($relKE) / $totalM;
                    // SPH-style critical specific energy — approximation
                    // Q*_D ~ 3e5 J/kg for rocky bodies (~1 km scale)
                    $qStar = 3e5 * pow($radius[$i] < $radius[$j] ? $radius[$i] : $radius[$j], -0.24);

                    $doFrag = ($fragThreshold > 0.0) && ($qD > $fragThreshold * $qStar);

                    if (!$doFrag) {
                        // ── Merge (perfectly inelastic) ──────────────────────
                        // Conservation of momentum
                        $vxNew = ($mass[$i] * $vx[$i] + $mass[$j] * $vx[$j]) / $totalM;
                        $vyNew = ($mass[$i] * $vy[$i] + $mass[$j] * $vy[$j]) / $totalM;

                        // Merged body at centre of mass position
                        $xNew = ($mass[$i] * $x[$i] + $mass[$j] * $x[$j]) / $totalM;
                        $yNew = ($mass[$i] * $y[$i] + $mass[$j] * $y[$j]) / $totalM;

                        // New radius from constant density assumption: r ~ cbrt(m)
                        // Keep density of larger body constant
                        $rhoRef    = $mass[$i] / (4.0/3.0 * M_PI * $radius[$i] ** 3);
                        $rNew      = pow($totalM / (4.0/3.0 * M_PI * $rhoRef), 1.0/3.0);

                        // Update body i (survivor = heavier body)
                        $x[$i]      = $xNew;
                        $y[$i]      = $yNew;
                        $vx[$i]     = $vxNew;
                        $vy[$i]     = $vyNew;
                        $mass[$i]   = $totalM;
                        $radius[$i] = $rNew;

                        // Deactivate body j
                        $active[$j] = false;
                        $merged[]   = $j;

                        $collisions[] = [
                            't'           => round($simTime, 2),
                            'type'        => 'merge',
                            'id_a'        => $id[$i],
                            'id_b'        => $id[$j],
                            'ke_diss'     => round($relKE, 4),
                            'result_mass' => round($totalM, 4),
                        ];

                    } else {
                        // ── Fragmentation ────────────────────────────────────
                        // Split larger body into two halves, smaller body
                        // loses mass proportional to Q_D / Q*_D.
                        // We create one new body and deactivate neither original
                        // (mass is redistributed). Cap at maxBodies.

                        $nNow = 0;
                        foreach ($active as $a2) { if ($a2) $nNow++; }

                        if ($nNow >= $maxBodies) {
                            // Cannot add bodies; force merge instead
                            $vxNew = ($mass[$i] * $vx[$i] + $mass[$j] * $vx[$j]) / $totalM;
                            $vyNew = ($mass[$i] * $vy[$i] + $mass[$j] * $vy[$j]) / $totalM;
                            $xNew  = ($mass[$i] * $x[$i] + $mass[$j] * $x[$j]) / $totalM;
                            $yNew  = ($mass[$i] * $y[$i] + $mass[$j] * $y[$j]) / $totalM;
                            $rhoRef = $mass[$i] / (4.0/3.0 * M_PI * $radius[$i] ** 3);
                            $rNew   = pow($totalM / (4.0/3.0 * M_PI * $rhoRef), 1.0/3.0);
                            $x[$i] = $xNew; $y[$i] = $yNew;
                            $vx[$i] = $vxNew; $vy[$i] = $vyNew;
                            $mass[$i] = $totalM; $radius[$i] = $rNew;
                            $active[$j] = false; $merged[] = $j;
                            continue;
                        }

                        // Fragment mass fraction: mass lost from smaller body
                        $fracLost    = min(0.5, 0.1 * ($qD / $qStar));
                        $massFrag    = $mass[$j] * $fracLost;

                        // Smaller body loses mass
                        $mass[$j]   -= $massFrag;
                        $rhoRef2     = $mass[$j] / max(1.0, (4.0/3.0 * M_PI * $radius[$j] ** 3 * (1.0 - $fracLost)));
                        $radius[$j]  = pow($mass[$j] / max(1e-30, (4.0/3.0 * M_PI * $rhoRef2)), 1.0/3.0);

                        // Fragment ejected with velocity = relative velocity + tangential kick
                        $normR = max(1e-10, $r);
                        $nvx   = $dx / $normR;
                        $nvy   = $dy / $normR;
                        $ejectSpeed = sqrt(2.0 * $relKE / max(1e-30, $massFrag)) * 0.4;

                        // New fragment body
                        $id[]     = $nextId++;
                        $x[]      = $x[$j] + $nvx * $radius[$j] * 2.0;
                        $y[]      = $y[$j] + $nvy * $radius[$j] * 2.0;
                        $vx[]     = $vx[$j] + $nvx * $ejectSpeed;
                        $vy[]     = $vy[$j] + $nvy * $ejectSpeed;
                        $mass[]   = $massFrag;
                        $rhoFrag  = $mass[$j] / max(1e-30, 4.0/3.0 * M_PI * $radius[$j] ** 3);
                        $radius[] = pow($massFrag / max(1e-30, 4.0/3.0 * M_PI * $rhoFrag), 1.0/3.0);
                        $fixed[]  = false;
                        $active[] = true;
                        $ax[]     = 0.0; $ay[] = 0.0;
                        $ax2[]    = 0.0; $ay2[] = 0.0;

                        $n = count($id);

                        $collisions[] = [
                            't'           => round($simTime, 2),
                            'type'        => 'frag',
                            'id_a'        => $id[$i],
                            'id_b'        => $id[$j],
                            'ke_diss'     => round($relKE * $fracLost, 4),
                            'result_mass' => round($massFrag, 4),
                        ];
                    }
                }
            }

            // ── Snapshot this frame ──────────────────────────────────────────
            $frameBody = [];
            $totalKE   = 0.0;
            $totalPE   = 0.0;
            $mMax      = 0.0;
            $nAlive    = 0;

            foreach ($x as $i => $xi) {
                if (!$active[$i]) continue;
                $nAlive++;
                $spd2     = $vx[$i] * $vx[$i] + $vy[$i] * $vy[$i];
                $ke       = 0.5 * $mass[$i] * $spd2;
                $totalKE += $ke;
                if ($mass[$i] > $mMax) $mMax = $mass[$i];

                // Approximate PE contribution for this body with central mass
                if (!$fixed[$i]) {
                    foreach ($x as $j => $xj) {
                        if (!$active[$j] || $j <= $i) continue;
                        $ddx = $xi - $xj;
                        $ddy = $y[$i] - $y[$j];
                        $rr  = sqrt($ddx * $ddx + $ddy * $ddy);
                        if ($rr > 0.0) {
                            $totalPE -= $G * $mass[$i] * $mass[$j] / $rr;
                        }
                    }
                }

                $frameBody[] = [
                    round($id[$i],     0),
                    round($xi,         2),
                    round($y[$i],      2),
                    round($vx[$i],     4),
                    round($vy[$i],     4),
                    round($mass[$i],   4),
                    round($radius[$i], 2),
                    $fixed[$i] ? 1 : 0,
                ];
            }

            $frameStates[] = [
                'bodies'   => $frameBody,
                't'        => round($simTime, 2),
                'n'        => $nAlive,
                'm_max'    => round($mMax, 4),
                'total_ke' => round($totalKE, 4),
                'total_pe' => round($totalPE, 4),
            ];
        }

        return [
            'states'     => $frameStates,
            'collisions' => $collisions,
            'finalTime'  => $simTime,
            'finalBodies'=> self::serializeBodies(
                $id, $x, $y, $vx, $vy, $mass, $radius, $fixed, $active
            ),
        ];
    }

    /** Serialize body parallel arrays into an associative array for the client. */
    private static function serializeBodies(
        array $id, array $x, array $y, array $vx, array $vy,
        array $mass, array $radius, array $fixed, array $active
    ): array {
        $out = [];
        foreach ($id as $i => $_) {
            if (!$active[$i]) continue;
            $out[] = [
                'id'     => $id[$i],
                'x'      => round($x[$i],      2),
                'y'      => round($y[$i],      2),
                'vx'     => round($vx[$i],     6),
                'vy'     => round($vy[$i],     6),
                'mass'   => round($mass[$i],   4),
                'radius' => round($radius[$i], 2),
                'fixed'  => $fixed[$i],
            ];
        }
        return $out;
    }

    // ─── PENDULUM BATCH ──────────────────────────────────────────────────────
    private static function batchPendulum(
        array  $p,
        int    $steps,
        string $integrator
    ): array {
        $theta   = (float)($p['theta']     ?? 0);
        $omega   = (float)($p['omega']     ?? 0);
        $time    = (float)($p['time']      ?? 0);
        $L       = (float)($p['length']    ?? 1);
        $g       = (float)($p['gravity']   ?? 9.81);
        $b       = (float)($p['damping']   ?? 0.1);
        $dAmp    = (float)($p['driveAmp']  ?? 0);
        $dFreq   = (float)($p['driveFreq'] ?? 0.667);
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
    private static function batchDoublePendulum(
        array  $p,
        int    $steps,
        string $integrator
    ): array {
        $t1   = (float)($p['theta1']  ?? 1.0);
        $t2   = (float)($p['theta2']  ?? 1.0);
        $o1   = (float)($p['omega1']  ?? 0.0);
        $o2   = (float)($p['omega2']  ?? 0.0);
        $L1   = (float)($p['length1'] ?? 1.0);
        $L2   = (float)($p['length2'] ?? 1.0);
        $m1   = (float)($p['mass1']   ?? 1.0);
        $m2   = (float)($p['mass2']   ?? 1.0);
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
        $driveAmp = (float)($params['driveAmp'] ?? 0);
        $driveFreq= (float)($params['driveFreq']?? 0.667);
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
        $t1 = (float)($params['theta1']  ?? 1);
        $t2 = (float)($params['theta2']  ?? 1);
        $o1 = (float)($params['omega1']  ?? 0);
        $o2 = (float)($params['omega2']  ?? 0);
        $L1 = (float)($params['length1'] ?? 1);
        $L2 = (float)($params['length2'] ?? 1);
        $m1 = (float)($params['mass1']   ?? 1);
        $m2 = (float)($params['mass2']   ?? 1);
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
    $raw  = file_get_contents('php://input');
    $json = json_decode($raw ?: '', true);
    $post = !empty($json) ? $json : $_POST;

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
