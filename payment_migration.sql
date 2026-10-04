-- ================================================================
-- DormEase – Payments Table Migration (MySQL 8.x compatible)
-- Adds rent_fee, wifi_fee, water_fee, electric_fee columns.
-- Run once via HeidiSQL or Laragon MySQL CLI.
-- ================================================================

USE dormease_db;

-- Add columns only when they do not already exist (via stored procedure)
DROP PROCEDURE IF EXISTS add_payment_columns;

DELIMITER $$

CREATE PROCEDURE add_payment_columns()
BEGIN
    -- rent_fee
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dormease_db'
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'rent_fee'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN rent_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
            COMMENT 'Base room rent portion' AFTER amount;
    END IF;

    -- wifi_fee
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dormease_db'
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'wifi_fee'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN wifi_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
            COMMENT 'WiFi / internet charge' AFTER rent_fee;
    END IF;

    -- water_fee
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dormease_db'
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'water_fee'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN water_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
            COMMENT 'Water consumption charge' AFTER wifi_fee;
    END IF;

    -- electric_fee
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = 'dormease_db'
          AND TABLE_NAME   = 'payments'
          AND COLUMN_NAME  = 'electric_fee'
    ) THEN
        ALTER TABLE payments
            ADD COLUMN electric_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00
            COMMENT 'Electricity charge' AFTER water_fee;
    END IF;

    -- Back-fill rent_fee from amount for existing rows
    UPDATE payments SET rent_fee = amount WHERE rent_fee = 0 AND amount > 0;

END$$

DELIMITER ;

CALL add_payment_columns();
DROP PROCEDURE IF EXISTS add_payment_columns;

-- Verify
SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'dormease_db' AND TABLE_NAME = 'payments'
ORDER BY ORDINAL_POSITION;
