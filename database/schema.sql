-- =====================================================
-- Web-Based Stage-Based Management System (Matatu)
-- schema.sql  - based on Section 4.3.4 / 4.3.5
-- Run in phpMyAdmin (XAMPP) -> Import, or: mysql -u root < schema.sql
-- =====================================================

CREATE DATABASE IF NOT EXISTS matatu_stage_db
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE matatu_stage_db;

-- Drop in reverse order of dependency (safe re-run while developing)
DROP TABLE IF EXISTS performance_log;
DROP TABLE IF EXISTS flagged_report;
DROP TABLE IF EXISTS queue_record;
DROP TABLE IF EXISTS travel_threshold;
DROP TABLE IF EXISTS departure_report;
DROP TABLE IF EXISTS user_account;
DROP TABLE IF EXISTS stage;
DROP TABLE IF EXISTS route;

-- 1. ROUTE
CREATE TABLE route (
  route_id      INT AUTO_INCREMENT PRIMARY KEY,
  route_number  VARCHAR(10)  NOT NULL UNIQUE,      -- e.g. 33, 111
  route_name    VARCHAR(100) NOT NULL,
  route_details TEXT NULL
) ENGINE=InnoDB;

-- 2. STAGE (stage_sequence = order along the route, 1 = starting stage)
CREATE TABLE stage (
  stage_id       INT AUTO_INCREMENT PRIMARY KEY,
  route_id       INT NOT NULL,
  stage_name     VARCHAR(100) NOT NULL,
  stage_sequence INT NOT NULL,
  UNIQUE KEY uq_route_sequence (route_id, stage_sequence),
  FOREIGN KEY (route_id) REFERENCES route(route_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3. USER_ACCOUNT (marshals and admins only; commuters have no account)
CREATE TABLE user_account (
  user_id           INT AUTO_INCREMENT PRIMARY KEY,
  username          VARCHAR(50)  NOT NULL UNIQUE,
  password_hash     VARCHAR(255) NOT NULL,         -- PHP password_hash()
  role              ENUM('marshal','admin') NOT NULL,
  assigned_stage_id INT NULL,                      -- marshals only
  FOREIGN KEY (assigned_stage_id) REFERENCES stage(stage_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 4. DEPARTURE_REPORT (time-stamped reports from commuters / marshals)
CREATE TABLE departure_report (
  report_id          INT AUTO_INCREMENT PRIMARY KEY,
  route_id           INT NOT NULL,
  stage_id           INT NOT NULL,
  reported_timestamp DATETIME NOT NULL,
  reported_location  VARCHAR(100) NULL,
  validation_status  ENUM('valid','flagged','pending') NOT NULL DEFAULT 'pending',
  source             ENUM('commuter','marshal','upload') NOT NULL DEFAULT 'commuter',
  session_token      VARCHAR(64) NULL,             -- anonymous token, no personal data
  INDEX idx_stage_time (stage_id, reported_timestamp),
  FOREIGN KEY (route_id) REFERENCES route(route_id) ON DELETE CASCADE,
  FOREIGN KEY (stage_id) REFERENCES stage(stage_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. TRAVEL_THRESHOLD (baseline minutes between two consecutive stages)
CREATE TABLE travel_threshold (
  threshold_id         INT AUTO_INCREMENT PRIMARY KEY,
  route_id             INT NOT NULL,
  from_stage_id        INT NOT NULL,
  to_stage_id          INT NOT NULL,
  baseline_travel_time DECIMAL(6,2) NOT NULL,      -- minutes
  threshold_limit      DECIMAL(6,2) NOT NULL,      -- maximum believable minutes
  FOREIGN KEY (route_id)      REFERENCES route(route_id) ON DELETE CASCADE,
  FOREIGN KEY (from_stage_id) REFERENCES stage(stage_id) ON DELETE CASCADE,
  FOREIGN KEY (to_stage_id)   REFERENCES stage(stage_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 6. QUEUE_RECORD (number of matatus waiting, set by marshals)
CREATE TABLE queue_record (
  queue_id          INT AUTO_INCREMENT PRIMARY KEY,
  stage_id          INT NOT NULL,
  queue_count       INT NOT NULL DEFAULT 0,
  updated_timestamp DATETIME NOT NULL,
  user_id           INT NULL,
  FOREIGN KEY (stage_id) REFERENCES stage(stage_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)  REFERENCES user_account(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 7. FLAGGED_REPORT (audit log of suspicious reports)
CREATE TABLE flagged_report (
  log_id            INT AUTO_INCREMENT PRIMARY KEY,
  report_id         INT NULL,
  user_id           INT NULL,                      -- admin who reviewed it
  log_type          VARCHAR(50)  NOT NULL,         -- 'duplicate' or 'outlier'
  details           VARCHAR(255) NULL,
  status            ENUM('open','overridden','rejected') NOT NULL DEFAULT 'open',
  created_timestamp DATETIME NOT NULL,
  FOREIGN KEY (report_id) REFERENCES departure_report(report_id) ON DELETE SET NULL,
  FOREIGN KEY (user_id)   REFERENCES user_account(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- 8. PERFORMANCE_LOG (daily analytics per route)
CREATE TABLE performance_log (
  performance_id    INT AUTO_INCREMENT PRIMARY KEY,
  route_id          INT NOT NULL,
  period_start      DATETIME NOT NULL,
  period_end        DATETIME NOT NULL,
  delay_metrics     TEXT NULL,
  created_timestamp DATETIME NOT NULL,
  FOREIGN KEY (route_id) REFERENCES route(route_id) ON DELETE CASCADE
) ENGINE=InnoDB;
