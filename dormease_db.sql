-- ================================================================
-- DormEase: Dormitory Management System
-- Database Schema & Sample Data
-- Run via HeidiSQL connected to Laragon's MySQL
-- ================================================================

CREATE DATABASE IF NOT EXISTS dormease_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

USE dormease_db;

-- ================================================================
-- 1. USERS TABLE
--    Central authentication table for all account types:
--    Dormitory Owner, Caretaker, Tenant
-- ================================================================
CREATE TABLE IF NOT EXISTS users (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(50)  NOT NULL UNIQUE,
    password    VARCHAR(255) NOT NULL,
    full_name   VARCHAR(100) NOT NULL,
    email       VARCHAR(100) NOT NULL UNIQUE,
    phone       VARCHAR(20)  DEFAULT NULL,
    role        ENUM('Dormitory Owner', 'Caretaker', 'Tenant') NOT NULL DEFAULT 'Tenant',
    avatar      VARCHAR(255) DEFAULT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_role (role),
    INDEX idx_active (is_active)
) ENGINE=InnoDB;

-- ================================================================
-- 2. ROOMS TABLE
--    Stores each dormitory room and its current status.
--    monthly_rate is the base rent price.
-- ================================================================
CREATE TABLE IF NOT EXISTS rooms (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    room_number   VARCHAR(10)  NOT NULL UNIQUE,
    floor         INT          NOT NULL DEFAULT 1,
    room_type     ENUM('Single', 'Double', 'Triple', 'Quad') NOT NULL DEFAULT 'Single',
    capacity      INT          NOT NULL DEFAULT 1,
    monthly_rate  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status        ENUM('Available', 'Occupied', 'Under Maintenance') NOT NULL DEFAULT 'Available',
    description   TEXT         DEFAULT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_status (status),
    INDEX idx_floor (floor)
) ENGINE=InnoDB;

-- ================================================================
-- 3. TENANTS TABLE
--    Links a user (role = 'Tenant') to a room.
--    Each record represents one tenancy period.
-- ================================================================
CREATE TABLE IF NOT EXISTS tenants (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT          NOT NULL,
    room_id         INT          NOT NULL,
    monthly_rent    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    move_in_date    DATE         NOT NULL,
    move_out_date   DATE         DEFAULT NULL,
    emergency_contact_name  VARCHAR(100) DEFAULT NULL,
    emergency_contact_phone VARCHAR(20)  DEFAULT NULL,
    status          ENUM('Active', 'Inactive', 'Pending') NOT NULL DEFAULT 'Pending',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_tenants_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    CONSTRAINT fk_tenants_room
        FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    INDEX idx_tenant_status (status),
    INDEX idx_user (user_id),
    INDEX idx_room (room_id)
) ENGINE=InnoDB;

-- ================================================================
-- 4. PAYMENTS TABLE
--    Tracks monthly rent payments per tenant.
--    payment_for stores which month/year the payment covers.
-- ================================================================
CREATE TABLE IF NOT EXISTS payments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT            NOT NULL,
    amount          DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    payment_date    DATE           DEFAULT NULL,
    payment_for     VARCHAR(20)    NOT NULL COMMENT 'Month & year covered, e.g. September 2026',
    payment_method  ENUM('Cash', 'GCash', 'Bank Transfer', 'Other') NOT NULL DEFAULT 'Cash',
    reference_no    VARCHAR(50)    DEFAULT NULL,
    status          ENUM('Paid', 'Pending', 'Overdue') NOT NULL DEFAULT 'Pending',
    remarks         TEXT           DEFAULT NULL,
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,

    INDEX idx_payment_status (status),
    INDEX idx_tenant (tenant_id),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB;


-- ================================================================
--  SAMPLE DATA
-- ================================================================

-- ────────────────────────────────────────────────────────────────
-- USERS  (password for ALL accounts: "password123")
-- Hash generated by PHP password_hash('password123', PASSWORD_DEFAULT)
-- ────────────────────────────────────────────────────────────────
INSERT INTO users (username, password, full_name, email, phone, role) VALUES
-- Dormitory Owner
('owner_rico',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ricardo Mendoza',    'rico@dormease.com',      '09171234567', 'Dormitory Owner'),

-- Caretakers
('caretaker_anna','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Anna Reyes',         'anna@dormease.com',      '09181234567', 'Caretaker'),
('caretaker_ben', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Benjamin Cruz',      'ben@dormease.com',       '09191234567', 'Caretaker'),

-- Tenants
('tenant_juan',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Juan Dela Cruz',     'juan@email.com',         '09201234567', 'Tenant'),
('tenant_maria',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Maria Santos',       'maria@email.com',        '09211234567', 'Tenant'),
('tenant_pedro',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Pedro Reyes',        'pedro@email.com',        '09221234567', 'Tenant'),
('tenant_jose',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Jose Garcia',        'jose@email.com',         '09231234567', 'Tenant'),
('tenant_ana',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ana Lim',            'ana@email.com',          '09241234567', 'Tenant'),
('tenant_carlo',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Carlo Rivera',       'carlo@email.com',        '09251234567', 'Tenant'),
('tenant_bea',    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Beatrice Navarro',   'bea@email.com',          '09261234567', 'Tenant');

-- ────────────────────────────────────────────────────────────────
-- ROOMS
-- ────────────────────────────────────────────────────────────────
INSERT INTO rooms (room_number, floor, room_type, capacity, monthly_rate, status, description) VALUES
-- 1st Floor
('101', 1, 'Single', 1, 3500.00, 'Occupied',            'Single room with window, ground floor'),
('102', 1, 'Single', 1, 3500.00, 'Occupied',            'Single room near entrance'),
('103', 1, 'Double', 2, 5000.00, 'Occupied',            'Double room with shared bathroom'),
('104', 1, 'Double', 2, 5000.00, 'Available',           'Double room with balcony'),
('105', 1, 'Single', 1, 3500.00, 'Under Maintenance',   'Plumbing repair in progress'),

-- 2nd Floor
('201', 2, 'Single', 1, 3800.00, 'Occupied',            'Single room, 2nd floor corner unit'),
('202', 2, 'Double', 2, 5500.00, 'Occupied',            'Double room with AC'),
('203', 2, 'Triple', 3, 7000.00, 'Available',           'Triple room, spacious layout'),
('204', 2, 'Single', 1, 3800.00, 'Available',           'Single room with study desk'),

-- 3rd Floor
('301', 3, 'Quad',   4, 9000.00, 'Occupied',            'Quad room, largest unit'),
('302', 3, 'Double', 2, 5500.00, 'Available',           'Double room with city view'),
('303', 3, 'Single', 1, 4000.00, 'Under Maintenance',   'Repainting scheduled');

-- ────────────────────────────────────────────────────────────────
-- TENANTS  (linking user accounts to rooms)
-- ────────────────────────────────────────────────────────────────
INSERT INTO tenants (user_id, room_id, monthly_rent, move_in_date, move_out_date, emergency_contact_name, emergency_contact_phone, status) VALUES
(4,  1,  3500.00, '2026-01-15', NULL,          'Elena Dela Cruz',  '09301111111', 'Active'),
(5,  2,  3500.00, '2026-02-01', NULL,          'Roberto Santos',   '09302222222', 'Active'),
(6,  3,  2500.00, '2026-03-10', NULL,          'Lorna Reyes',      '09303333333', 'Active'),
(7,  3,  2500.00, '2026-03-10', NULL,          'Carmen Garcia',    '09304444444', 'Active'),
(8,  6,  3800.00, '2026-04-01', NULL,          'David Lim',        '09305555555', 'Active'),
(9,  7,  2750.00, '2026-05-15', NULL,          'Sofia Rivera',     '09306666666', 'Active'),
(10, 10, 2250.00, '2026-06-01', NULL,          'Miguel Navarro',   '09307777777', 'Active');

-- ────────────────────────────────────────────────────────────────
-- PAYMENTS  (recent months)
-- ────────────────────────────────────────────────────────────────
INSERT INTO payments (tenant_id, amount, payment_date, payment_for, payment_method, reference_no, status, remarks) VALUES
-- Juan Dela Cruz (tenant_id 1)
(1, 3500.00, '2026-07-05', 'July 2026',      'GCash',         'GC-20260705-001', 'Paid',    NULL),
(1, 3500.00, '2026-08-03', 'August 2026',    'GCash',         'GC-20260803-001', 'Paid',    NULL),
(1, 3500.00, '2026-09-02', 'September 2026', 'Cash',          NULL,              'Paid',    'Paid on time'),

-- Maria Santos (tenant_id 2)
(2, 3500.00, '2026-07-08', 'July 2026',      'Bank Transfer', 'BT-20260708-002', 'Paid',    NULL),
(2, 3500.00, '2026-08-06', 'August 2026',    'Bank Transfer', 'BT-20260806-002', 'Paid',    NULL),
(2, 3500.00, NULL,         'September 2026', 'Cash',          NULL,              'Pending', 'Due Sep 5'),

-- Pedro Reyes (tenant_id 3)
(3, 2500.00, '2026-07-04', 'July 2026',      'Cash',          NULL,              'Paid',    NULL),
(3, 2500.00, '2026-08-10', 'August 2026',    'GCash',         'GC-20260810-003', 'Paid',    'Late by 5 days'),
(3, 2500.00, NULL,         'September 2026', 'Cash',          NULL,              'Overdue', 'Follow up sent'),

-- Jose Garcia (tenant_id 4)
(4, 2500.00, '2026-07-03', 'July 2026',      'Cash',          NULL,              'Paid',    NULL),
(4, 2500.00, '2026-08-02', 'August 2026',    'Cash',          NULL,              'Paid',    NULL),
(4, 2500.00, '2026-09-01', 'September 2026', 'GCash',         'GC-20260901-004', 'Paid',    NULL),

-- Ana Lim (tenant_id 5)
(5, 3800.00, '2026-07-07', 'July 2026',      'Bank Transfer', 'BT-20260707-005', 'Paid',    NULL),
(5, 3800.00, '2026-08-05', 'August 2026',    'Bank Transfer', 'BT-20260805-005', 'Paid',    NULL),
(5, 3800.00, NULL,         'September 2026', 'Bank Transfer', NULL,              'Pending', NULL),

-- Carlo Rivera (tenant_id 6)
(6, 2750.00, '2026-07-06', 'July 2026',      'GCash',         'GC-20260706-006', 'Paid',    NULL),
(6, 2750.00, NULL,         'August 2026',    'Cash',          NULL,              'Overdue', 'No response to reminders'),
(6, 2750.00, NULL,         'September 2026', 'Cash',          NULL,              'Overdue', 'Will follow up with caretaker'),

-- Beatrice Navarro (tenant_id 7)
(7, 2250.00, '2026-07-02', 'July 2026',      'Cash',          NULL,              'Paid',    'Early payment'),
(7, 2250.00, '2026-08-04', 'August 2026',    'GCash',         'GC-20260804-007', 'Paid',    NULL),
(7, 2250.00, '2026-09-03', 'September 2026', 'GCash',         'GC-20260903-007', 'Paid',    NULL);
