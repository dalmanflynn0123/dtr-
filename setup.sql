CREATE DATABASE IF NOT EXISTS dtr_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dtr_system;

-- ── EMPLOYEES TABLE ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employees (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    device_id     VARCHAR(50)  NOT NULL UNIQUE,   
    employee_id   VARCHAR(50)  NOT NULL,           
    full_name     VARCHAR(150) NOT NULL,           
    department    VARCHAR(100) DEFAULT '',      
    position      VARCHAR(100) DEFAULT '',         
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── ATTENDANCE TABLE ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    device_id     VARCHAR(50)  NOT NULL,           
    work_date     DATE         NOT NULL,
    time_in       TIME         NULL,
    time_out      TIME         NULL,
    source_file   VARCHAR(100) DEFAULT '',        
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_punch (device_id, work_date)     
) ENGINE=InnoDB;

-- ── SETTINGS TABLE ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(100) PRIMARY KEY,
    setting_value TEXT         DEFAULT ''
) ENGINE=InnoDB;


INSERT INTO settings (setting_key, setting_value) VALUES
    ('company_name',    'MALAKING IBONG BUGHAW INC.,'),
    ('company_address', '7th Floor, Semicon Corporate Building No. 50 Marcos Highway Brgy. Dela Paz, Pasig City '),
    ('department',      'HEAD OFFICE'),
    ('approver',        '')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

