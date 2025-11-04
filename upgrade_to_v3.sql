-- Upgrade script to align existing HRassess databases with the v3.0 schema requirements.
-- The statements are idempotent and can be run multiple times.

-- Ensure site_config table exists with all required columns.
CREATE TABLE IF NOT EXISTS site_config (
  id INT PRIMARY KEY,
  site_name VARCHAR(200) NULL,
  landing_text TEXT NULL,
  address VARCHAR(255) NULL,
  contact VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL,
  footer_org_name VARCHAR(255) NULL,
  footer_org_short VARCHAR(100) NULL,
  footer_website_label VARCHAR(255) NULL,
  footer_website_url VARCHAR(255) NULL,
  footer_email VARCHAR(255) NULL,
  footer_phone VARCHAR(255) NULL,
  footer_hotline_label VARCHAR(255) NULL,
  footer_hotline_number VARCHAR(50) NULL,
  footer_rights VARCHAR(255) NULL,
  google_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  google_oauth_client_id VARCHAR(255) NULL,
  google_oauth_client_secret VARCHAR(255) NULL,
  microsoft_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  microsoft_oauth_client_id VARCHAR(255) NULL,
  microsoft_oauth_client_secret VARCHAR(255) NULL,
  microsoft_oauth_tenant VARCHAR(255) NULL,
  color_theme VARCHAR(50) NOT NULL DEFAULT 'light',
  brand_color VARCHAR(7) NULL,
  enabled_locales TEXT NULL,
  smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  smtp_host VARCHAR(255) NULL,
  smtp_port INT NULL,
  smtp_username VARCHAR(255) NULL,
  smtp_password VARCHAR(255) NULL,
  smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'none',
  smtp_from_email VARCHAR(255) NULL,
  smtp_from_name VARCHAR(255) NULL,
  smtp_timeout INT NULL,
  upgrade_repo VARCHAR(255) NULL,
  review_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_templates LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE site_config
  ADD COLUMN IF NOT EXISTS site_name VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS landing_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS contact VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_org_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_org_short VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS footer_website_label VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_website_url VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_email VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_phone VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_hotline_label VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS footer_hotline_number VARCHAR(50) NULL,
  ADD COLUMN IF NOT EXISTS footer_rights VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS google_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS google_oauth_client_id VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS google_oauth_client_secret VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS microsoft_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS microsoft_oauth_client_id VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS microsoft_oauth_client_secret VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS microsoft_oauth_tenant VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS color_theme VARCHAR(50) NOT NULL DEFAULT 'light',
  ADD COLUMN IF NOT EXISTS brand_color VARCHAR(7) NULL,
  ADD COLUMN IF NOT EXISTS enabled_locales TEXT NULL,
  ADD COLUMN IF NOT EXISTS smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS smtp_host VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS smtp_port INT NULL,
  ADD COLUMN IF NOT EXISTS smtp_username VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS smtp_password VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'none',
  ADD COLUMN IF NOT EXISTS smtp_from_email VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS smtp_from_name VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS smtp_timeout INT NULL,
  ADD COLUMN IF NOT EXISTS upgrade_repo VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS review_enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS email_templates LONGTEXT NULL;

INSERT INTO site_config (
  id, site_name, landing_text, address, contact, logo_path,
  footer_org_name, footer_org_short, footer_website_label, footer_website_url,
  footer_email, footer_phone, footer_hotline_label, footer_hotline_number,
  footer_rights, google_oauth_enabled, google_oauth_client_id, google_oauth_client_secret,
  microsoft_oauth_enabled, microsoft_oauth_client_id, microsoft_oauth_client_secret, microsoft_oauth_tenant,
  color_theme, brand_color, enabled_locales, smtp_enabled, smtp_host, smtp_port, smtp_username, smtp_password,
  smtp_encryption, smtp_from_email, smtp_from_name, smtp_timeout, upgrade_repo, review_enabled, email_templates
) VALUES (
  1, 'My Performance', NULL, NULL, NULL, NULL,
  'Ethiopian Pharmaceutical Supply Service', 'EPSS / EPS', 'epss.gov.et', 'https://epss.gov.et',
  'info@epss.gov.et', '+251 11 155 9900', 'Hotline 939', '939',
  'All rights reserved.', 0, NULL, NULL,
  0, NULL, NULL, 'common',
  'light', '#2073bf', '["en","fr","am"]', 0, NULL, 587, NULL, NULL,
  'none', NULL, NULL, 20, 'khoppenworth/HRassessv300', 1, '{}'
) ON DUPLICATE KEY UPDATE
  site_name = COALESCE(site_config.site_name, VALUES(site_name)),
  brand_color = IFNULL(site_config.brand_color, VALUES(brand_color)),
  color_theme = IFNULL(site_config.color_theme, VALUES(color_theme)),
  enabled_locales = IFNULL(site_config.enabled_locales, VALUES(enabled_locales)),
  smtp_port = IFNULL(site_config.smtp_port, VALUES(smtp_port)),
  smtp_timeout = IFNULL(site_config.smtp_timeout, VALUES(smtp_timeout)),
  upgrade_repo = IFNULL(site_config.upgrade_repo, VALUES(upgrade_repo)),
  review_enabled = IFNULL(site_config.review_enabled, VALUES(review_enabled)),
  email_templates = IFNULL(site_config.email_templates, VALUES(email_templates));

-- Ensure users table columns match the application expectations.
ALTER TABLE users
  MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff';

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS account_status ENUM('pending','active','disabled') NOT NULL DEFAULT 'active' AFTER language,
  ADD COLUMN IF NOT EXISTS next_assessment_date DATE NULL AFTER account_status,
  ADD COLUMN IF NOT EXISTS approved_by INT NULL AFTER next_assessment_date,
  ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL AFTER approved_by,
  ADD COLUMN IF NOT EXISTS sso_provider VARCHAR(50) NULL AFTER approved_at;

SET @has_fk_users_approved_by := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_users_approved_by'
);
SET @sql_users_fk := IF(
  @has_fk_users_approved_by = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_users_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure questionnaire_item includes the is_required flag.
ALTER TABLE questionnaire
  ADD COLUMN IF NOT EXISTS status ENUM('draft','published','inactive') NOT NULL DEFAULT 'draft' AFTER description;

ALTER TABLE questionnaire_section
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER order_index;

ALTER TABLE questionnaire_item
  ADD COLUMN IF NOT EXISTS is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_multiple;

ALTER TABLE questionnaire_item
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_required;

UPDATE questionnaire
SET status = 'draft'
WHERE status IS NULL OR status NOT IN ('draft','published','inactive');

UPDATE questionnaire_section
SET is_active = 1
WHERE is_active IS NULL;

UPDATE questionnaire_item
SET is_active = 1
WHERE is_active IS NULL;

-- Ensure work function definitions exist and mappings use flexible keys.
CREATE TABLE IF NOT EXISTS work_function_definition (
  wf_key VARCHAR(64) NOT NULL,
  label VARCHAR(190) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  display_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (wf_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO work_function_definition (wf_key, label, display_order) VALUES
  ('finance', 'Finance', 1),
  ('general_service', 'General Service', 2),
  ('hrm', 'HRM', 3),
  ('ict', 'ICT', 4),
  ('leadership_tn', 'Leadership TN', 5),
  ('legal_service', 'Legal Service', 6),
  ('pme', 'PME', 7),
  ('quantification', 'Quantification', 8),
  ('records_documentation', 'Records & Documentation', 9),
  ('security_driver', 'Security & Driver', 10),
  ('security', 'Security', 11),
  ('tmd', 'TMD', 12),
  ('wim', 'WIM', 13),
  ('cmd', 'CMD', 14),
  ('communication', 'Communication', 15),
  ('dfm', 'DFM', 16),
  ('driver', 'Driver', 17),
  ('ethics', 'Ethics', 18)
ON DUPLICATE KEY UPDATE label = VALUES(label);

ALTER TABLE users MODIFY COLUMN work_function VARCHAR(64) DEFAULT NULL;

SET @v3_users_fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'work_function'
    AND REFERENCED_TABLE_NAME = 'work_function_definition'
  LIMIT 1
);
SET @v3_users_fk_sql := IF(
  @v3_users_fk IS NULL,
  'ALTER TABLE users ADD CONSTRAINT fk_users_work_function FOREIGN KEY (work_function) REFERENCES work_function_definition(wf_key) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @v3_users_fk_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS questionnaire_work_function (
  questionnaire_id INT NOT NULL,
  work_function VARCHAR(64) NOT NULL,
  PRIMARY KEY (questionnaire_id, work_function)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE questionnaire_work_function MODIFY COLUMN work_function VARCHAR(64) NOT NULL;

SET @has_qwf_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_work_function'
    AND INDEX_NAME = 'idx_qwf_work_function'
);
SET @sql_qwf_idx := IF(
  @has_qwf_idx = 0,
  'ALTER TABLE questionnaire_work_function ADD KEY idx_qwf_work_function (work_function);',
  'SELECT 1'
);
PREPARE stmt FROM @sql_qwf_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_qwf_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_work_function'
    AND CONSTRAINT_NAME = 'fk_qwf_definition'
);
SET @sql_qwf_fk := IF(
  @has_qwf_fk = 0,
  'ALTER TABLE questionnaire_work_function ADD CONSTRAINT fk_qwf_definition FOREIGN KEY (work_function) REFERENCES work_function_definition(wf_key) ON DELETE CASCADE ON UPDATE CASCADE;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_qwf_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO work_function_definition (wf_key, label)
SELECT DISTINCT wf.work_function, wf.work_function
FROM questionnaire_work_function wf
LEFT JOIN work_function_definition def ON def.wf_key = wf.work_function
WHERE def.wf_key IS NULL;

INSERT INTO work_function_definition (wf_key, label)
SELECT DISTINCT u.work_function, u.work_function
FROM users u
LEFT JOIN work_function_definition def ON def.wf_key = u.work_function
WHERE u.work_function IS NOT NULL AND u.work_function <> '' AND def.wf_key IS NULL;

ALTER TABLE course_catalogue MODIFY COLUMN recommended_for VARCHAR(64) NOT NULL;

SET @has_catalogue_fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'course_catalogue'
    AND COLUMN_NAME = 'recommended_for'
    AND REFERENCED_TABLE_NAME = 'work_function_definition'
  LIMIT 1
);
SET @sql_catalogue_fk := IF(
  @has_catalogue_fk = 0,
  'ALTER TABLE course_catalogue ADD CONSTRAINT fk_course_catalogue_work_function FOREIGN KEY (recommended_for) REFERENCES work_function_definition(wf_key) ON DELETE RESTRICT;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_catalogue_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO work_function_definition (wf_key, label)
SELECT DISTINCT c.recommended_for, c.recommended_for
FROM course_catalogue c
LEFT JOIN work_function_definition def ON def.wf_key = c.recommended_for
WHERE c.recommended_for IS NOT NULL AND c.recommended_for <> '' AND def.wf_key IS NULL;

-- Ensure questionnaire_assignment exists for supervisor targeting.
CREATE TABLE IF NOT EXISTS questionnaire_assignment (
  staff_id INT NOT NULL,
  questionnaire_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id, questionnaire_id),
  KEY idx_assignment_questionnaire (questionnaire_id),
  KEY idx_assignment_assigned_by (assigned_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE questionnaire_assignment
  ADD COLUMN IF NOT EXISTS assigned_by INT NULL AFTER questionnaire_id,
  ADD COLUMN IF NOT EXISTS assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER assigned_by;

SET @has_assignment_staff_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_assignment'
    AND CONSTRAINT_NAME = 'fk_assignment_staff'
);
SET @sql_assignment_staff_fk := IF(
  @has_assignment_staff_fk = 0,
  'ALTER TABLE questionnaire_assignment ADD CONSTRAINT fk_assignment_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_assignment_staff_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_assignment_questionnaire_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_assignment'
    AND CONSTRAINT_NAME = 'fk_assignment_questionnaire'
);
SET @sql_assignment_questionnaire_fk := IF(
  @has_assignment_questionnaire_fk = 0,
  'ALTER TABLE questionnaire_assignment ADD CONSTRAINT fk_assignment_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_assignment_questionnaire_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_assignment_supervisor_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_assignment'
    AND CONSTRAINT_NAME = 'fk_assignment_supervisor'
);
SET @sql_assignment_supervisor_fk := IF(
  @has_assignment_supervisor_fk = 0,
  'ALTER TABLE questionnaire_assignment ADD CONSTRAINT fk_assignment_supervisor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_assignment_supervisor_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_assignment_questionnaire_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_assignment'
    AND INDEX_NAME = 'idx_assignment_questionnaire'
);
SET @sql_assignment_questionnaire_idx := IF(
  @has_assignment_questionnaire_idx = 0,
  'CREATE INDEX idx_assignment_questionnaire ON questionnaire_assignment (questionnaire_id);',
  'SELECT 1'
);
PREPARE stmt FROM @sql_assignment_questionnaire_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_assignment_assigned_by_idx := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'questionnaire_assignment'
    AND INDEX_NAME = 'idx_assignment_assigned_by'
);
SET @sql_assignment_assigned_by_idx := IF(
  @has_assignment_assigned_by_idx = 0,
  'CREATE INDEX idx_assignment_assigned_by ON questionnaire_assignment (assigned_by);',
  'SELECT 1'
);
PREPARE stmt FROM @sql_assignment_assigned_by_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ensure analytics_report_schedule exists for scheduled report delivery.
CREATE TABLE IF NOT EXISTS analytics_report_schedule (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipients TEXT NOT NULL,
  frequency ENUM('daily','weekly','monthly') NOT NULL DEFAULT 'weekly',
  next_run_at DATETIME NOT NULL,
  last_run_at DATETIME NULL,
  created_by INT NULL,
  questionnaire_id INT NULL,
  include_details TINYINT(1) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_report_schedule_next_run (next_run_at),
  KEY idx_report_schedule_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE analytics_report_schedule
  ADD COLUMN IF NOT EXISTS last_run_at DATETIME NULL AFTER next_run_at,
  ADD COLUMN IF NOT EXISTS created_by INT NULL AFTER last_run_at,
  ADD COLUMN IF NOT EXISTS questionnaire_id INT NULL AFTER created_by,
  ADD COLUMN IF NOT EXISTS include_details TINYINT(1) NOT NULL DEFAULT 0 AFTER questionnaire_id,
  ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER include_details,
  ADD COLUMN IF NOT EXISTS created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER active,
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

SET @has_schedule_next_run_idx := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'analytics_report_schedule'
    AND INDEX_NAME = 'idx_report_schedule_next_run'
);
SET @sql_schedule_next_run_idx := IF(
  @has_schedule_next_run_idx = 0,
  'ALTER TABLE analytics_report_schedule ADD INDEX idx_report_schedule_next_run (next_run_at);',
  'SELECT 1'
);
PREPARE stmt FROM @sql_schedule_next_run_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schedule_active_idx := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'analytics_report_schedule'
    AND INDEX_NAME = 'idx_report_schedule_active'
);
SET @sql_schedule_active_idx := IF(
  @has_schedule_active_idx = 0,
  'ALTER TABLE analytics_report_schedule ADD INDEX idx_report_schedule_active (active);',
  'SELECT 1'
);
PREPARE stmt FROM @sql_schedule_active_idx;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schedule_creator_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'analytics_report_schedule'
    AND CONSTRAINT_NAME = 'fk_report_schedule_creator'
);
SET @sql_schedule_creator_fk := IF(
  @has_schedule_creator_fk = 0,
  'ALTER TABLE analytics_report_schedule ADD CONSTRAINT fk_report_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_schedule_creator_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_schedule_questionnaire_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'analytics_report_schedule'
    AND CONSTRAINT_NAME = 'fk_report_schedule_questionnaire'
);
SET @sql_schedule_questionnaire_fk := IF(
  @has_schedule_questionnaire_fk = 0,
  'ALTER TABLE analytics_report_schedule ADD CONSTRAINT fk_report_schedule_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE SET NULL;',
  'SELECT 1'
);
PREPARE stmt FROM @sql_schedule_questionnaire_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
