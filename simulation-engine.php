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

class SimulationEngine {

    // Installation fingerprint for tracking
    private static function getFingerprint(): string {
        return hash('sha256', ($_SERVER['HTTP_HOST'] ?? '') . __DIR__ . 'akilesh-physics');
    }

    // ─── BATCH ROUTER ────────────────────────────────────────────────────────
    /**
     * Run N simulation steps and return the full state array.
     * Each element: [theta, omega, t, x, y, ke, pe, hdrift]
     *
     * @param string $type       Simulation identifier
     * @param array  $params     Initial state + physics params
     * @param int    $steps      Number of frames to compute (max 600)
     * @param string $integrator 'rk4' | 'symplectic' | 'euler'
     * @return array             { states: [...], finalState: {...} }
     */
    public static function batchCompute(
        string $type,
        array  $params,
        int    $steps = 120,
        string $integrator = 'rk4'
    ): array {
        $steps = min(max(1, $steps), 600); // safety clamp

        error_log(sprintf(
            '[BATCH] type:%s steps:%d integ:%s fp:%s',
            $type, $steps, $integrator, self::getFingerprint()
        ));

        switch ($type) {
            case 'pendulum':
                return self::batchPendulum($params, $steps, $integrator);
            default:
                throw new \InvalidArgumentException("Unknown simulation type: $type");
        }
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

        // Compute H0 from initial state for drift tracking
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
                default: // rk4
                    [$theta, $omega] = self::rk4Pendulum(
                        $theta, $omega, $time, $dt, $L, $g, $b, $dAmp, $dFreq
                    );
            }
            $time += $dt;

            $ke     = 0.5 * $L * $L * $omega * $omega;
            $pe     = $g * $L * (1.0 - cos($theta));
            $hdrift = ($ke + $pe) - $H0;

            // [theta, omega, t, x, y, ke, pe, hdrift]
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
            'finalState' => [
                'theta' => $theta,
                'omega' => $omega,
                'time'  => $time,
            ],
        ];
    }

    // ─── HAMILTONIAN ─────────────────────────────────────────────────────────
    private static function hamiltonianPendulum(
        float $theta, float $omega, float $L, float $g
    ): float {
        $mass = 1.0; // normalised
        return 0.5 * $mass * $L * $L * $omega * $omega
             + $mass * $g * $L * (1.0 - cos($theta));
    }

    // ─── INTEGRATORS ─────────────────────────────────────────────────────────

    /** RK4 — 4th-order accuracy, good all-rounder */
    private static function rk4Pendulum(
        float $th, float $om, float $t, float $dt,
        float $L,  float $g, float $b,
        float $dAmp, float $dFreq
    ): array {
        $deriv = static function (float $th, float $om, float $t)
            use ($L, $g, $b, $dAmp, $dFreq): array {
            return [
                $om,
                -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t),
            ];
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

    /**
     * Symplectic (Störmer–Verlet leapfrog) — conserves phase-space volume.
     * Energy drift is orders of magnitude smaller than RK4 over long runs.
     * Ideal for Hamiltonian systems with no driving force.
     */
    private static function symplecticPendulum(
        float $th, float $om, float $t, float $dt,
        float $L,  float $g, float $b,
        float $dAmp, float $dFreq
    ): array {
        // half-step momentum update
        $alpha_half = -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t);
        $om_half    = $om + 0.5 * $dt * $alpha_half;

        // full-step position update
        $th_new = $th + $dt * $om_half;

        // half-step momentum update with new position
        $alpha_new = -($g / $L) * sin($th_new) - $b * $om_half + $dAmp * cos($dFreq * ($t + $dt));
        $om_new    = $om_half + 0.5 * $dt * $alpha_new;

        return [$th_new, $om_new];
    }

    /**
     * Forward Euler — first-order, energy-drifting.
     * Included deliberately so students can observe numerical instability.
     */
    private static function eulerPendulum(
        float $th, float $om, float $t, float $dt,
        float $L,  float $g, float $b,
        float $dAmp, float $dFreq
    ): array {
        $alpha = -($g / $L) * sin($th) - $b * $om + $dAmp * cos($dFreq * $t);
        return [
            $th + $dt * $om,
            $om + $dt * $alpha,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  LEGACY SINGLE-STEP METHODS (kept for backward compatibility)
    // ─────────────────────────────────────────────────────────────────────────

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
            $theta, $omega, $time, $dt,
            $length, $gravity, $damping, $driveAmp, $driveFreq
        );

        $v  = abs($omega) * $length;
        $h  = $length * (1 - cos($theta));
        $ke = 0.5 * $v * $v;
        $pe = $gravity * $h;

        return [
            'theta' => $newTheta,
            'omega' => $newOmega,
            'ke'    => $ke,
            'pe'    => $pe,
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
            $type,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            self::getFingerprint()
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
    $raw    = file_get_contents('php://input');
    $json   = json_decode($raw ?: '', true);
    $post   = !empty($json) ? $json : $_POST;

    $action = $post['action'] ?? 'single';
    $type   = $post['type']   ?? '';

    if (empty($type)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing simulation type']);
        exit;
    }

    if ($action === 'batch') {
        $steps      = (int)  ($post['steps']      ?? 120);
        $integrator = (string)($post['integrator'] ?? 'rk4');
        $result     = SimulationEngine::batchCompute($type, $post, $steps, $integrator);
    } else {
        // Legacy single-step
        $result = SimulationEngine::calculate($type, $post);
    }

    echo json_encode($result);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
