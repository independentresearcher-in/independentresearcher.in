<?php
/**
 * Physics Simulation Engine - Server-Side Rendering
 * 
 * WATERMARKS:
 * - Code Author: Pancham Kotian
 * - Website Owner: Akilesh Bhandary  
 * - AI-Generated Code: Claude (Anthropic)
 * 
 * Copyright (c) 2025 Akilesh Bhandary
 * All Rights Reserved - Proprietary Code
 * 
 * This code contains proprietary physics algorithms.
 * Unauthorized copying, distribution, or reverse engineering prohibited.
 */

class SimulationEngine {
    
    // Installation fingerprint for tracking
    private static function getFingerprint() {
        return hash('sha256', $_SERVER['HTTP_HOST'] . __DIR__ . 'akilesh-physics');
    }
    
    /**
     * Pendulum Simulation - RK4 Integration
     * Algorithm: Runge-Kutta 4th order for driven damped pendulum
     */
    public static function calculatePendulum($params) {
        $theta = floatval($params['theta'] ?? 0);
        $omega = floatval($params['omega'] ?? 0);
        $time = floatval($params['time'] ?? 0);
        $length = floatval($params['length'] ?? 1);
        $gravity = floatval($params['gravity'] ?? 9.81);
        $damping = floatval($params['damping'] ?? 0.1);
        $driveAmp = floatval($params['driveAmp'] ?? 0);
        $driveFreq = floatval($params['driveFreq'] ?? 0.667);
        $dt = floatval($params['dt'] ?? 0.016); // 60 FPS default
        
        // RK4 Integration - Proprietary implementation
        list($newTheta, $newOmega) = self::rk4Pendulum(
            $theta, $omega, $time, $dt,
            $length, $gravity, $damping, $driveAmp, $driveFreq
        );
        
        // Calculate energy
        $mass = 1.0; // Normalized
        $v = abs($omega) * $length;
        $h = $length * (1 - cos($theta));
        
        $ke = 0.5 * $mass * $v * $v;
        $pe = $mass * $gravity * $h;
        
        return [
            'theta' => $newTheta,
            'omega' => $newOmega,
            'ke' => $ke,
            'pe' => $pe,
            'time' => $time + $dt,
            'x' => sin($newTheta) * $length,
            'y' => cos($newTheta) * $length
        ];
    }
    
    private static function rk4Pendulum($th, $om, $t, $dt, $L, $g, $damp, $dAmp, $dFreq) {
        $deriv = function($th, $om, $t) use ($L, $g, $damp, $dAmp, $dFreq) {
            return [
                $om,
                -($g / $L) * sin($th) - $damp * $om + $dAmp * cos($dFreq * $t)
            ];
        };
        
        list($k1t, $k1o) = $deriv($th, $om, $t);
        list($k2t, $k2o) = $deriv($th + 0.5*$dt*$k1t, $om + 0.5*$dt*$k1o, $t + 0.5*$dt);
        list($k3t, $k3o) = $deriv($th + 0.5*$dt*$k2t, $om + 0.5*$dt*$k2o, $t + 0.5*$dt);
        list($k4t, $k4o) = $deriv($th + $dt*$k3t, $om + $dt*$k3o, $t + $dt);
        
        $newTheta = $th + $dt/6 * ($k1t + 2*$k2t + 2*$k3t + $k4t);
        $newOmega = $om + $dt/6 * ($k1o + 2*$k2o + 2*$k3o + $k4o);
        
        return [$newTheta, $newOmega];
    }
    
    /**
     * Double Pendulum - Chaotic system
     */
    public static function calculateDoublePendulum($params) {
        // Extract parameters
        $t1 = floatval($params['theta1'] ?? 1);
        $t2 = floatval($params['theta2'] ?? 1);
        $o1 = floatval($params['omega1'] ?? 0);
        $o2 = floatval($params['omega2'] ?? 0);
        $L1 = floatval($params['length1'] ?? 1);
        $L2 = floatval($params['length2'] ?? 1);
        $m1 = floatval($params['mass1'] ?? 1);
        $m2 = floatval($params['mass2'] ?? 1);
        $g = floatval($params['gravity'] ?? 9.81);
        $dt = floatval($params['dt'] ?? 0.016);
        
        // RK4 for coupled equations
        list($newT1, $newT2, $newO1, $newO2) = self::rk4DoublePendulum(
            $t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g, $dt
        );
        
        // Calculate positions
        $x1 = $L1 * sin($newT1);
        $y1 = -$L1 * cos($newT1);
        $x2 = $x1 + $L2 * sin($newT2);
        $y2 = $y1 - $L2 * cos($newT2);
        
        return [
            'theta1' => $newT1,
            'theta2' => $newT2,
            'omega1' => $newO1,
            'omega2' => $newO2,
            'x1' => $x1,
            'y1' => $y1,
            'x2' => $x2,
            'y2' => $y2
        ];
    }
    
    private static function rk4DoublePendulum($t1, $t2, $o1, $o2, $L1, $L2, $m1, $m2, $g, $dt) {
        $deriv = function($t1, $t2, $o1, $o2) use ($L1, $L2, $m1, $m2, $g) {
            $dtheta = $t1 - $t2;
            $den1 = ($m1 + $m2) * $L1 - $m2 * $L1 * cos($dtheta) * cos($dtheta);
            $den2 = ($L2 / $L1) * $den1;
            
            $a1 = ($m2 * $L1 * $o1 * $o1 * sin($dtheta) * cos($dtheta) +
                   $m2 * $g * sin($t2) * cos($dtheta) +
                   $m2 * $L2 * $o2 * $o2 * sin($dtheta) -
                   ($m1 + $m2) * $g * sin($t1)) / $den1;
            
            $a2 = (-$m2 * $L2 * $o2 * $o2 * sin($dtheta) * cos($dtheta) +
                   ($m1 + $m2) * ($g * sin($t1) * cos($dtheta) -
                   $L1 * $o1 * $o1 * sin($dtheta) - $g * sin($t2))) / $den2;
            
            return [$o1, $o2, $a1, $a2];
        };
        
        list($k1t1, $k1t2, $k1o1, $k1o2) = $deriv($t1, $t2, $o1, $o2);
        list($k2t1, $k2t2, $k2o1, $k2o2) = $deriv(
            $t1 + 0.5*$dt*$k1t1, $t2 + 0.5*$dt*$k1t2,
            $o1 + 0.5*$dt*$k1o1, $o2 + 0.5*$dt*$k1o2
        );
        list($k3t1, $k3t2, $k3o1, $k3o2) = $deriv(
            $t1 + 0.5*$dt*$k2t1, $t2 + 0.5*$dt*$k2t2,
            $o1 + 0.5*$dt*$k2o1, $o2 + 0.5*$dt*$k2o2
        );
        list($k4t1, $k4t2, $k4o1, $k4o2) = $deriv(
            $t1 + $dt*$k3t1, $t2 + $dt*$k3t2,
            $o1 + $dt*$k3o1, $o2 + $dt*$k3o2
        );
        
        $newT1 = $t1 + $dt/6 * ($k1t1 + 2*$k2t1 + 2*$k3t1 + $k4t1);
        $newT2 = $t2 + $dt/6 * ($k1t2 + 2*$k2t2 + 2*$k3t2 + $k4t2);
        $newO1 = $o1 + $dt/6 * ($k1o1 + 2*$k2o1 + 2*$k3o1 + $k4o1);
        $newO2 = $o2 + $dt/6 * ($k1o2 + 2*$k2o2 + 2*$k3o2 + $k4o2);
        
        return [$newT1, $newT2, $newO1, $newO2];
    }
    
    /**
     * Projectile Motion with Air Resistance
     */
    public static function calculateProjectile($params) {
        $x = floatval($params['x'] ?? 0);
        $y = floatval($params['y'] ?? 0);
        $vx = floatval($params['vx'] ?? 10);
        $vy = floatval($params['vy'] ?? 10);
        $g = floatval($params['gravity'] ?? 9.81);
        $drag = floatval($params['drag'] ?? 0);
        $dt = floatval($params['dt'] ?? 0.016);
        
        // Euler integration with air resistance
        $speed = sqrt($vx*$vx + $vy*$vy);
        $dragForceX = -$drag * $speed * $vx;
        $dragForceY = -$drag * $speed * $vy;
        
        $newVx = $vx + $dragForceX * $dt;
        $newVy = $vy + ($dragForceY - $g) * $dt;
        $newX = $x + $newVx * $dt;
        $newY = $y + $newVy * $dt;
        
        return [
            'x' => $newX,
            'y' => $newY,
            'vx' => $newVx,
            'vy' => $newVy,
            'speed' => sqrt($newVx*$newVx + $newVy*$newVy)
        ];
    }
    
    /**
     * Orbital Mechanics - 2-body problem
     */
    public static function calculateOrbital($params) {
        $x = floatval($params['x'] ?? 100);
        $y = floatval($params['y'] ?? 0);
        $vx = floatval($params['vx'] ?? 0);
        $vy = floatval($params['vy'] ?? 10);
        $centralMass = floatval($params['centralMass'] ?? 1000);
        $G = floatval($params['G'] ?? 1);
        $dt = floatval($params['dt'] ?? 0.016);
        
        // Calculate gravitational force
        $r = sqrt($x*$x + $y*$y);
        if ($r < 0.1) $r = 0.1; // Prevent division by zero
        
        $force = $G * $centralMass / ($r * $r);
        $ax = -$force * ($x / $r);
        $ay = -$force * ($y / $r);
        
        // Velocity Verlet integration
        $newVx = $vx + $ax * $dt;
        $newVy = $vy + $ay * $dt;
        $newX = $x + $newVx * $dt;
        $newY = $y + $newVy * $dt;
        
        return [
            'x' => $newX,
            'y' => $newY,
            'vx' => $newVx,
            'vy' => $newVy,
            'distance' => sqrt($newX*$newX + $newY*$newY)
        ];
    }
    
    /**
     * Particle in Fluid - Buoyancy and Drag
     */
    public static function calculateParticleFluid($params) {
        $y = floatval($params['y'] ?? 0);
        $vy = floatval($params['vy'] ?? 0);
        $mass = floatval($params['mass'] ?? 1);
        $radius = floatval($params['radius'] ?? 0.1);
        $fluidDensity = floatval($params['fluidDensity'] ?? 1000);
        $particleDensity = floatval($params['particleDensity'] ?? 500);
        $g = floatval($params['gravity'] ?? 9.81);
        $dt = floatval($params['dt'] ?? 0.016);
        
        // Calculate forces
        $volume = (4.0/3.0) * M_PI * pow($radius, 3);
        $buoyancyForce = $fluidDensity * $volume * $g;
        $weight = $particleDensity * $volume * $g;
        $dragCoeff = 6 * M_PI * $radius * 0.001; // Stokes drag
        $dragForce = -$dragCoeff * $vy;
        
        $netForce = $buoyancyForce - $weight + $dragForce;
        $acceleration = $netForce / $mass;
        
        $newVy = $vy + $acceleration * $dt;
        $newY = $y + $newVy * $dt;
        
        return [
            'y' => $newY,
            'vy' => $newVy,
            'buoyancy' => $buoyancyForce,
            'weight' => $weight,
            'drag' => abs($dragForce)
        ];
    }
    
    /**
     * Route simulation calculation
     */
    public static function calculate($type, $params) {
        // Log calculation (watermark tracking)
        error_log(sprintf(
            '[SIMULATION] Type:%s IP:%s Fingerprint:%s',
            $type,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            self::getFingerprint()
        ));
        
        switch ($type) {
            case 'pendulum':
                return self::calculatePendulum($params);
            
            case 'double-pendulum':
                return self::calculateDoublePendulum($params);
            
            case 'projectile':
                return self::calculateProjectile($params);
            
            case 'orbital':
                return self::calculateOrbital($params);
            
            case 'particle-fluid':
            case 'buoyancy':
                return self::calculateParticleFluid($params);
            
            default:
                throw new Exception('Unknown simulation type: ' . $type);
        }
    }
}
