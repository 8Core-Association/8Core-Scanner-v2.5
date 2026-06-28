-- 8Core Scanner v2.0 — Shema baze podataka
-- (c) 2026 Tomislav Galić / 8Core
-- Web: https://8core.hr
--
-- Napomena: Ovaj fajl je referentna shema.
-- Za instalaciju koristite installer (install/index.php) koji automatski kreira sve tablice.
-- Za nadogradnju koristite install/migrate.php.
--
-- Verzija sheme: 2.0.0
-- Datum:         2026-06-28

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- scans — historija skeniranja
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scans` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `started_at`   DATETIME        NOT NULL,
  `finished_at`  DATETIME        NULL,
  `base_path`    VARCHAR(500)    NOT NULL,
  `target_type`  VARCHAR(30)     NULL,
  `target_value` VARCHAR(255)    NULL,
  `files_found`  INT UNSIGNED    NOT NULL DEFAULT 0,
  `status`       VARCHAR(30)     NOT NULL DEFAULT 'RUNNING',
  PRIMARY KEY (`id`),
  KEY `idx_status`       (`status`),
  KEY `idx_started_at`   (`started_at`),
  KEY `idx_target_type`  (`target_type`),
  KEY `idx_target_value` (`target_value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- findings — pronađeni sumnjivi fajlovi
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `findings` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scan_id`         BIGINT UNSIGNED NOT NULL,
  `rule_name`       VARCHAR(150)    NOT NULL,
  `risk`            VARCHAR(20)     NOT NULL,
  `account_name`    VARCHAR(80)     NULL,
  `owner_name`      VARCHAR(80)     NULL,
  `group_name`      VARCHAR(80)     NULL,
  `perms`           VARCHAR(20)     NULL,
  `file_size`       BIGINT UNSIGNED NULL,
  `file_name`       VARCHAR(255)    NULL,
  `file_ext`        VARCHAR(30)     NULL,
  `file_path`       TEXT            NOT NULL,
  `relative_path`   TEXT            NULL,
  `mtime`           DATETIME        NULL,
  `ctime`           DATETIME        NULL,
  `birth_time`      DATETIME        NULL,
  `detected_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source_guess`    VARCHAR(255)    NULL,
  `source_type`     VARCHAR(80)     NULL,
  `sha256`          CHAR(64)        NULL,
  `action_status`   VARCHAR(40)     NOT NULL DEFAULT 'new',
  `action_note`     TEXT            NULL,
  `action_at`       DATETIME        NULL,
  `action_by`       VARCHAR(80)     NULL,
  `quarantine_path` TEXT            NULL,
  `action_error`    TEXT            NULL,
  `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_scan_id`       (`scan_id`),
  KEY `idx_risk`          (`risk`),
  KEY `idx_rule_name`     (`rule_name`),
  KEY `idx_account_name`  (`account_name`),
  KEY `idx_owner_name`    (`owner_name`),
  KEY `idx_file_ext`      (`file_ext`),
  KEY `idx_detected_at`   (`detected_at`),
  KEY `idx_action_status` (`action_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- scanner_actions — audit log akcija na nalazima
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scanner_actions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `finding_id` BIGINT UNSIGNED NOT NULL,
  `action`     VARCHAR(40)     NOT NULL,
  `note`       TEXT            NULL,
  `created_at` DATETIME        NOT NULL,
  `created_by` VARCHAR(80)     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_finding_id` (`finding_id`),
  KEY `idx_action`     (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- scanner_users — korisnici web panela
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scanner_users` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(80)   NOT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,
  `role`          ENUM('admin','user') NOT NULL DEFAULT 'user',
  `account_name`  VARCHAR(80)   NULL,
  `email`         VARCHAR(180)  NULL,
  `active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`    DATETIME      NOT NULL,
  `last_login`    DATETIME      NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  KEY `idx_role`         (`role`),
  KEY `idx_account_name` (`account_name`),
  KEY `idx_active`       (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- scanner_user_accounts — many-to-many korisnik <-> hosting account
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scanner_user_accounts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `account_name` VARCHAR(80)  NOT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_account` (`user_id`, `account_name`),
  KEY `idx_user_id`      (`user_id`),
  KEY `idx_account_name` (`account_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- scanner_rules — IOC pravila (čita ih bash scanner engine)
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scanner_rules` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(200) NOT NULL,
  `description` TEXT         NULL,
  `type`        ENUM('filename','path','regex','regex_content','sha256','chmod','extension','filesize') NOT NULL DEFAULT 'regex',
  `pattern`     VARCHAR(1000) NOT NULL,
  `extensions`  VARCHAR(500) NULL,
  `risk`        ENUM('CRITICAL','HIGH','MEDIUM','LOW','INFO') NOT NULL DEFAULT 'MEDIUM',
  `active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `note`        TEXT         NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  `created_by`  VARCHAR(80)  NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type`   (`type`),
  KEY `idx_risk`   (`risk`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- scanner_ignore_list — ignoriranje specifičnih fajlova/putanja/hasheva
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scanner_ignore_list` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category`   ENUM('file','path','hash','user') NOT NULL,
  `value`      VARCHAR(1000) NOT NULL,
  `note`       TEXT          NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` VARCHAR(80)   NULL,
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category`),
  KEY `idx_value`    (`value`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────────
-- scanner_scan_requests — queue zahtjeva za skeniranje
-- ─────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `scanner_scan_requests` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requested_by`   VARCHAR(80)     NOT NULL,
  `requested_role` VARCHAR(20)     NOT NULL,
  `target_type`    VARCHAR(30)     NOT NULL DEFAULT 'account',
  `target_value`   VARCHAR(255)    NOT NULL,
  `status`         VARCHAR(30)     NOT NULL DEFAULT 'PENDING',
  `scan_id`        BIGINT UNSIGNED NULL,
  `requested_at`   DATETIME        NOT NULL,
  `started_at`     DATETIME        NULL,
  `finished_at`    DATETIME        NULL,
  `note`           TEXT            NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status`       (`status`),
  KEY `idx_requested_by` (`requested_by`),
  KEY `idx_target_type`  (`target_type`),
  KEY `idx_target_value` (`target_value`),
  KEY `idx_requested_at` (`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;
