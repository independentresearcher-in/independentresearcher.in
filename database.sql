-- =============================================================================
-- IndependentResearcher Platform — Full Database Schema
-- Run this in phpMyAdmin > SQL tab on a fresh DB.
-- Safe to re-run: uses IF NOT EXISTS and ON DUPLICATE KEY UPDATE throughout.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS u802044488_test_db_163
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE u802044488_test_db_163;

-- =============================================================================
-- USERS
-- =============================================================================
CREATE TABLE IF NOT EXISTS users (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
    email                      VARCHAR(255) UNIQUE NOT NULL,
    password_hash              VARCHAR(255)       NULL      default NULL,          -- NULL for Google-only accounts
    display_name               VARCHAR(80)        NULL,
    role                       ENUM('user','admin') DEFAULT 'user',
    auth_provider              VARCHAR(20)        DEFAULT 'email',
    google_id                  VARCHAR(255)       NULL,
    google_email               VARCHAR(255)       NULL,
    avatar_url                 VARCHAR(500)       NULL,
    email_verified             TINYINT(1)         DEFAULT 0,
    verification_token         VARCHAR(64)        NULL,
    verification_token_expires DATETIME           NULL,
    reset_token                VARCHAR(64)        NULL,
    reset_token_expires        DATETIME           NULL,
    created_at                 TIMESTAMP          DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP          NULL ON UPDATE CURRENT_TIMESTAMP,
    last_login                 TIMESTAMP          NULL,

    INDEX idx_email          (email),
    INDEX idx_google_id      (google_id),
    INDEX idx_auth_provider  (auth_provider),
    INDEX idx_verification   (verification_token),
    INDEX idx_reset          (reset_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PRODUCTS (simulations)
-- =============================================================================
CREATE TABLE IF NOT EXISTS products (
    id            VARCHAR(50)     PRIMARY KEY,
    title         VARCHAR(255)    NOT NULL,
    description   TEXT,
    price         DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
    currency      VARCHAR(3)      DEFAULT 'INR',
    sim_file      VARCHAR(255)    NOT NULL,
    paid_file     VARCHAR(255)    NOT NULL,
    preview_image VARCHAR(255),
    tags          JSON,
    is_active     BOOLEAN         DEFAULT TRUE,
    is_free_trial BOOLEAN         DEFAULT TRUE,   -- TRUE = accessible on trial tier
    created_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PURCHASES
-- =============================================================================
CREATE TABLE IF NOT EXISTS purchases (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT            NOT NULL,
    product_id     VARCHAR(50)    NOT NULL,
    payment_id     VARCHAR(255),
    amount         DECIMAL(10,2)  DEFAULT 0.00,
    currency       VARCHAR(3)     DEFAULT 'INR',
    status         ENUM('pending','paid','failed','demo','trial') DEFAULT 'pending',
    access_type    ENUM('paid','trial','demo')                    DEFAULT 'trial',
    payment_method VARCHAR(50),
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      NULL ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_product (user_id, product_id),  -- INSERT IGNORE deduplication
    INDEX idx_status (status),
    INDEX idx_user_product (user_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PAYMENT TRANSACTIONS (Razorpay audit log)
-- =============================================================================
CREATE TABLE IF NOT EXISTS payment_transactions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id   INT            NOT NULL,
    gateway       VARCHAR(50)    NOT NULL,
    order_id      VARCHAR(255),
    payment_id    VARCHAR(255),
    signature     VARCHAR(255),
    status        VARCHAR(50),
    response_data JSON,
    created_at    TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    INDEX idx_order   (order_id),
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- DONATIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS donations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT            NULL,
    donor_name     VARCHAR(255)   NOT NULL,
    donor_email    VARCHAR(255)   NOT NULL,
    amount         DECIMAL(10,2)  NOT NULL,
    currency       VARCHAR(3)     DEFAULT 'INR',
    payment_id     VARCHAR(255),
    payment_method VARCHAR(50),
    status         ENUM('pending','completed','failed') DEFAULT 'pending',
    message        TEXT,
    is_anonymous   BOOLEAN        DEFAULT FALSE,
    show_in_donors BOOLEAN        DEFAULT TRUE,
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      NULL ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status  (status),
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- PHYSICS QUESTIONS
-- =============================================================================
CREATE TABLE IF NOT EXISTS physics_questions (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    email          VARCHAR(255)   NOT NULL,
    question       TEXT           NOT NULL,
    with_donation  BOOLEAN        DEFAULT FALSE,
    ip_address     VARCHAR(45)    NULL,
    user_agent     VARCHAR(255)   NULL,
    status         ENUM('pending','answered','spam') DEFAULT 'pending',
    answered_at    TIMESTAMP      NULL,
    created_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      NULL ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_email   (email),
    INDEX idx_status  (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- WEBHOOK LOG (Razorpay webhook audit)
-- =============================================================================
CREATE TABLE IF NOT EXISTS webhook_log (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    event        VARCHAR(100),
    payload      LONGTEXT,
    status       VARCHAR(50)   DEFAULT 'received',
    processed_at TIMESTAMP     NULL,
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SIMULATIONS — all marked is_free_trial = TRUE (paid tier coming soon)
-- =============================================================================
INSERT INTO products (id, title, description, price, currency, sim_file, tags, is_active, is_free_trial) VALUES
('sim-pendulum',          'Simple Pendulum Dynamics',      'Explore pendulum physics with real-time simulation featuring variable gravity, damping, and energy plots.',             249.00, 'INR', 'sim-pendulum.html',          '["mechanics","oscillation","beginner"]',   TRUE, TRUE),
('sim-orbital',           'Orbital Mechanics & Gravity',   'Simulate planetary orbits and gravitational interactions with interactive controls.',                                  299.00, 'INR', 'sim-orbital.html',           '["gravity","astronomy","orbital"]',        TRUE, TRUE),
('sim-double-pendulum',   'Double Pendulum Chaos',         'Experience chaotic motion in a double pendulum system with real-time visualization.',                                  299.00, 'INR', 'sim-double-pendulum.html',   '["mechanics","chaos","advanced"]',         TRUE, TRUE),
('sim-projectile',        'Projectile Motion',             'Study projectile motion with adjustable launch angles, velocities, and gravitational fields.',                         249.00, 'INR', 'sim-projectile.html',        '["mechanics","kinematics","beginner"]',    TRUE, TRUE),
('sim-gravity',           'N-Body Gravity Simulator',      'Simulate multiple bodies under mutual gravitational attraction with collision detection.',                              349.00, 'INR', 'sim-gravity.html',           '["gravity","n-body","advanced"]',          TRUE, TRUE),
('sim-gravity-asteroid',  'Asteroid Belt Simulation',      'Explore asteroid dynamics in a gravitational field with interactive particle systems.',                                 299.00, 'INR', 'sim-gravity-asteroid.html',  '["gravity","astronomy","particles"]',      TRUE, TRUE),
('sim-buoyancy',          'Buoyancy & Fluid Dynamics',     'Study buoyancy forces and fluid behavior with adjustable density and volume controls.',                                249.00, 'INR', 'sim-buoyancy.html',          '["fluids","forces","intermediate"]',       TRUE, TRUE),
('sim-viscous-fluid',     'Viscous Fluid Flow',            'Visualize fluid flow patterns with adjustable viscosity and boundary conditions.',                                      299.00, 'INR', 'sim-viscous-fluid.html',     '["fluids","flow","advanced"]',             TRUE, TRUE),
('sim-particle-floating', 'Particle Suspension System',    'Observe particle behavior in floating and suspension scenarios with gravity effects.',                                  249.00, 'INR', 'sim-particle-floating.html', '["particles","fluids","intermediate"]',    TRUE, TRUE),
('sim-energy-sources',    'Energy Source Comparison',      'Compare different energy sources and their efficiency characteristics.',                                               199.00, 'INR', 'sim-energy-sources.html',    '["energy","thermodynamics","beginner"]',   TRUE, TRUE)
ON DUPLICATE KEY UPDATE
    is_free_trial = TRUE,
    is_active     = TRUE;

-- =============================================================================
-- MIGRATION: safe ALTER statements for existing deployments
-- Each is wrapped to avoid errors if columns already exist.
-- =============================================================================

-- Add missing columns to users if upgrading from old schema
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='auth_provider') = 0,
    'ALTER TABLE users ADD COLUMN auth_provider VARCHAR(20) DEFAULT "email" AFTER email_verified',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='google_id') = 0,
    'ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL AFTER auth_provider',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='avatar_url') = 0,
    'ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='is_free_trial') = 0,
    'ALTER TABLE products ADD COLUMN is_free_trial BOOLEAN DEFAULT TRUE AFTER is_active',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchases' AND COLUMN_NAME='access_type') = 0,
    'ALTER TABLE purchases ADD COLUMN access_type ENUM("paid","trial","demo") DEFAULT "trial" AFTER status',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Ensure 'trial' is in purchases.status enum
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchases' AND COLUMN_NAME='status'
     AND COLUMN_TYPE LIKE '%trial%') = 0,
    'ALTER TABLE purchases MODIFY COLUMN status ENUM("pending","paid","failed","demo","trial") DEFAULT "pending"',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Ensure password_hash column exists (old schema used 'password')
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='password_hash') = 0,
    'ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL AFTER email',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Mark all existing simulations as free trial
UPDATE products SET is_free_trial = TRUE WHERE is_free_trial IS NULL OR is_free_trial = FALSE;

-- ── display_name column for user profiles (added 2026-03-30) ────────────────
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='display_name') = 0,
    'ALTER TABLE users ADD COLUMN display_name VARCHAR(80) NULL AFTER email',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ════════════════════════════════════════════════════════════════════════════
-- BLOG, RATINGS, COMMENTS, USAGE TRACKING  (2026-03-31)
-- ════════════════════════════════════════════════════════════════════════════

-- Blog posts
CREATE TABLE IF NOT EXISTS blog_posts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(200) NOT NULL UNIQUE,
    title       VARCHAR(300) NOT NULL,
    summary     VARCHAR(500) NULL,
    content     MEDIUMTEXT   NOT NULL,
    cover_image VARCHAR(500) NULL,
    status      ENUM('draft','published') DEFAULT 'draft',
    author_id   INT UNSIGNED NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status_created (status, created_at)
);

-- Simulation star ratings (1 row per user per sim)
CREATE TABLE IF NOT EXISTS sim_ratings (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(100) NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    rating     TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_product (user_id, product_id),
    KEY idx_product (product_id)
);

-- Simulation comments (paginated, infinite scroll)
CREATE TABLE IF NOT EXISTS sim_comments (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(100) NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL, row per session)
CREATE TABLE IF NOT EXISTS sim_usage (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(100) NOT NULL,
    user_id    INT UNSIGNED NULL,       -- NULL = anonymous
    session_id VARCHAR(64)  NOT NULL,
    seconds    INT UNSIGNED DEFAULT 0,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ended_at   DATETIME NULL,
    KEY idx_product   (product_id),
    KEY idx_user      (user_id),
    KEY idx_session   (session_id)
);

-- This line selects admin.
UPDATE users SET role = 'admin' WHERE email = 'bhandaryaki@gmail.com';

-- ── paid_file column for products (2026-04-01) ────────────────────────────────
SET @s = (SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='paid_file') = 0,
    'ALTER TABLE products ADD COLUMN paid_file VARCHAR(255) NULL AFTER sim_file',
    'SELECT 1'
)); PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;



-- ── Set a trigger for default display name ───────────────
DROP TRIGGER IF EXISTS set_default_display_name;

-- get display name from email if null
DELIMITER //
CREATE TRIGGER set_default_display_name
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.display_name IS NULL OR NEW.display_name = '' THEN
        SET NEW.display_name = SUBSTRING_INDEX(NEW.email, '@', 1);
    END IF;
END; //
DELIMITER ;
