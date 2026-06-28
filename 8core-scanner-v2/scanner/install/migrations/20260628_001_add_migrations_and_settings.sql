-- 8Core Scanner v2.0 — Migracija 001
-- Dodaje tablice scanner_migrations i scanner_settings
-- Sigurno za ponovljeno pokretanje (IF NOT EXISTS)

CREATE TABLE IF NOT EXISTS `scanner_migrations` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `migration_name` VARCHAR(191) NOT NULL,
  `applied_at`     DATETIME     NOT NULL,
  UNIQUE KEY `uq_migration_name` (`migration_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `scanner_settings` (
  `setting_key`   VARCHAR(191) NOT NULL,
  `setting_value` TEXT         NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `scanner_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES
  ('installed_version', '2.0.0', NOW()),
  ('installed_at',      NOW(),   NOW()),
  ('last_updated_at',   NULL,    NOW());
