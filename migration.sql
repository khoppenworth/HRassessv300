
-- migration.sql: upgrade existing DB to enhanced schema
ALTER TABLE questionnaire_item ADD COLUMN IF NOT EXISTS weight_percent INT NOT NULL DEFAULT 0;
ALTER TABLE questionnaire ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER description;
ALTER TABLE questionnaire_section ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER order_index;
ALTER TABLE questionnaire_item ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER weight_percent;
UPDATE questionnaire SET is_active=1 WHERE is_active IS NULL;
UPDATE questionnaire_section SET is_active=1 WHERE is_active IS NULL;
UPDATE questionnaire_item SET is_active=1 WHERE is_active IS NULL;
CREATE TABLE IF NOT EXISTS site_config (
  id INT PRIMARY KEY,
  site_name VARCHAR(200) NULL,
  landing_text TEXT NULL,
  address VARCHAR(255) NULL,
  contact VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO site_config (id, site_name) VALUES (1, 'EPSS Self-Assessment');
