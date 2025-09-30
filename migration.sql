
-- migration.sql: upgrade existing DB to enhanced schema
ALTER TABLE questionnaire_item ADD COLUMN weight_percent INT NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS site_config (
  id INT PRIMARY KEY,
  site_name VARCHAR(200) NULL,
  landing_text TEXT NULL,
  address VARCHAR(255) NULL,
  contact VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO site_config (id, site_name) VALUES (1, 'EPSS Self-Assessment');
