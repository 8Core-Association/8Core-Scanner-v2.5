-- 8Core Scanner v2.0 — Migracija 002
-- Dodaje rule_key kolonu u scanner_rules (hash od name+pattern+type za deduplikaciju)
-- Sigurno za ponovljeno pokretanje

ALTER TABLE `scanner_rules`
  ADD COLUMN IF NOT EXISTS `rule_key` VARCHAR(64) NULL AFTER `name`,
  ADD COLUMN IF NOT EXISTS `imported_from` VARCHAR(255) NULL AFTER `created_by`;

-- Popuni rule_key za postojeće retke koji nemaju ključ
-- rule_key = SHA2(CONCAT(name, '|', pattern, '|', type), 256)
UPDATE `scanner_rules`
SET `rule_key` = LEFT(SHA2(CONCAT(`name`, '|', `pattern`, '|', `type`), 256), 32)
WHERE `rule_key` IS NULL OR `rule_key` = '';

-- Dodaj unique index ako ga nema (ignoriraj duplikate pri importu)
-- Koristimo proceduralni pristup jer IF NOT EXISTS nije podržan za INDEX u MySQL 5.7
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'scanner_rules'
    AND index_name = 'uq_rule_key'
);

SET @sql = IF(@idx_exists = 0,
  'ALTER TABLE `scanner_rules` ADD UNIQUE KEY `uq_rule_key` (`rule_key`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
