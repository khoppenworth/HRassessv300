
-- Enhanced init.sql (adds site_config and weight_percent)
DROP TABLE IF EXISTS training_recommendation;
DROP TABLE IF EXISTS analytics_report_schedule;
DROP TABLE IF EXISTS questionnaire_assignment;
DROP TABLE IF EXISTS questionnaire_work_function;
DROP TABLE IF EXISTS course_catalogue;
DROP TABLE IF EXISTS questionnaire_response_item;
DROP TABLE IF EXISTS questionnaire_response;
DROP TABLE IF EXISTS questionnaire_item_option;
DROP TABLE IF EXISTS questionnaire_item;
DROP TABLE IF EXISTS questionnaire_section;
DROP TABLE IF EXISTS questionnaire;
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS performance_period;
DROP TABLE IF EXISTS site_config;

CREATE TABLE site_config (
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
  enabled_locales TEXT NULL,
  smtp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  smtp_host VARCHAR(255) NULL,
  smtp_port INT NULL,
  smtp_username VARCHAR(255) NULL,
  smtp_password VARCHAR(255) NULL,
  smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'none',
  smtp_from_email VARCHAR(255) NULL,
  smtp_from_name VARCHAR(255) NULL,
  smtp_timeout INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','supervisor','staff') NOT NULL DEFAULT 'staff',
  full_name VARCHAR(200) NULL,
  email VARCHAR(200) NULL,
  gender ENUM('female','male','other','prefer_not_say') DEFAULT NULL,
  date_of_birth DATE NULL,
  phone VARCHAR(50) NULL,
  department VARCHAR(150) NULL,
  cadre VARCHAR(150) NULL,
  work_function ENUM('finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification','records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics') NOT NULL DEFAULT 'general_service',
  profile_completed TINYINT(1) NOT NULL DEFAULT 0,
  language VARCHAR(5) NOT NULL DEFAULT 'en',
  account_status ENUM('pending','active','disabled') NOT NULL DEFAULT 'active',
  next_assessment_date DATE NULL,
  first_login_at DATETIME NULL,
  approved_by INT NULL,
  approved_at DATETIME NULL,
  sso_provider VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  action VARCHAR(100) NOT NULL,
  meta JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE performance_period (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(50) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  UNIQUE KEY uniq_period_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_section (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  order_index INT NOT NULL DEFAULT 0,
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_id INT NOT NULL,
  section_id INT NULL,
  linkId VARCHAR(64) NOT NULL,
  text VARCHAR(500) NOT NULL,
  type ENUM('likert','text','textarea','boolean','choice') NOT NULL DEFAULT 'likert',
  order_index INT NOT NULL DEFAULT 0,
  weight_percent INT NOT NULL DEFAULT 0,
  allow_multiple TINYINT(1) NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES questionnaire_section(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_item_option (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_item_id INT NOT NULL,
  value VARCHAR(500) NOT NULL,
  order_index INT NOT NULL DEFAULT 0,
  FOREIGN KEY (questionnaire_item_id) REFERENCES questionnaire_item(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_response (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  questionnaire_id INT NOT NULL,
  performance_period_id INT NOT NULL,
  status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
  score INT NULL, -- percentage 0..100
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_comment TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE,
  FOREIGN KEY (performance_period_id) REFERENCES performance_period(id) ON DELETE RESTRICT,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_user_questionnaire_period (user_id, questionnaire_id, performance_period_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_response_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  response_id INT NOT NULL,
  linkId VARCHAR(64) NOT NULL,
  answer JSON NOT NULL,
  FOREIGN KEY (response_id) REFERENCES questionnaire_response(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE course_catalogue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  moodle_url VARCHAR(255) NULL,
  recommended_for ENUM('finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification','records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics') NOT NULL,
  min_score INT NOT NULL DEFAULT 0,
  max_score INT NOT NULL DEFAULT 99,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE training_recommendation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_response_id INT NOT NULL,
  course_id INT NOT NULL,
  recommendation_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (questionnaire_response_id) REFERENCES questionnaire_response(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES course_catalogue(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_work_function (
  questionnaire_id INT NOT NULL,
  work_function ENUM('finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification','records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics') NOT NULL,
  PRIMARY KEY (questionnaire_id, work_function),
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_assignment (
  staff_id INT NOT NULL,
  questionnaire_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (staff_id, questionnaire_id),
  KEY idx_assignment_questionnaire (questionnaire_id),
  KEY idx_assignment_assigned_by (assigned_by),
  CONSTRAINT fk_assignment_staff FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE,
  CONSTRAINT fk_assignment_supervisor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE analytics_report_schedule (
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
  KEY idx_report_schedule_active (active),
  CONSTRAINT fk_report_schedule_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_report_schedule_questionnaire FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO site_config (
  id,
  site_name,
  landing_text,
  address,
  contact,
  logo_path,
  footer_org_name,
  footer_org_short,
  footer_website_label,
  footer_website_url,
  footer_email,
  footer_phone,
  footer_hotline_label,
  footer_hotline_number,
  footer_rights,
  google_oauth_enabled,
  google_oauth_client_id,
  google_oauth_client_secret,
  microsoft_oauth_enabled,
  microsoft_oauth_client_id,
  microsoft_oauth_client_secret,
  microsoft_oauth_tenant,
  color_theme,
  enabled_locales,
  smtp_enabled,
  smtp_host,
  smtp_port,
  smtp_username,
  smtp_password,
  smtp_encryption,
  smtp_from_email,
  smtp_from_name,
  smtp_timeout
) VALUES (
  1,
  'My Performance',
  NULL,
  NULL,
  NULL,
  NULL,
  'Ethiopian Pharmaceutical Supply Service',
  'EPSS / EPS',
  'epss.gov.et',
  'https://epss.gov.et',
  'info@epss.gov.et',
  '+251 11 155 9900',
  'Hotline 939',
  '939',
  'All rights reserved.',
  0,
  NULL,
  NULL,
  0,
  NULL,
  NULL,
  'common',
  'light',
  '["en","fr","am"]',
  0,
  NULL,
  587,
  NULL,
  NULL,
  'none',
  NULL,
  NULL,
  20
);

-- default users (bcrypt hashes should be set during runtime; using demo placeholder hashes)
INSERT INTO users (username,password,role,full_name,email) VALUES
('admin', '$2y$12$XN2nF1L1uUah/ESe7CO4f.Dwnx/C8J91JINMz4jXTtLDXPWlzBzGe', 'admin', 'System Admin', 'admin@example.com'),
('super', '$2y$10$Pj9m0H6b8K2ZyQe7p0k1TOeGq1bqfP3QO3Y6b5g1YQb1J2lL8mJxC', 'supervisor', 'Default Supervisor', 'super@example.com'),
('staff', '$2y$10$Pj9m0H6b8K2ZyQe7p0k1TOeGq1bqfP3QO3Y6b5g1YQb1J2lL8mJxC', 'staff', 'Sample Staff', 'staff@example.com');

-- sample questionnaire with weights
INSERT INTO questionnaire (title, description) VALUES ('Baseline Staff Self-Assessment', 'Initial EPSS self-assessment');
SET @qid = LAST_INSERT_ID();
INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES
(@qid, 'general_service'),
(@qid, 'hrm'),
(@qid, 'ict'),
(@qid, 'finance'),
(@qid, 'leadership_tn');
INSERT INTO questionnaire_section (questionnaire_id,title,description,order_index) VALUES
(@qid,'Core Competencies','General capability checks',1),
(@qid,'Facility & Process','Process and facility checks',2);
SET @s1 = (SELECT id FROM questionnaire_section WHERE questionnaire_id=@qid AND order_index=1);
SET @s2 = (SELECT id FROM questionnaire_section WHERE questionnaire_id=@qid AND order_index=2);
INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent) VALUES
(@qid, @s1, 'q1', 'Understands SOPs for dispensing?', 'boolean', 1, 20),
(@qid, @s1, 'q2', 'List key essential medicines handled daily', 'text', 2, 20),
(@qid, @s1, 'q3', 'Describe one challenge faced this week', 'textarea', 3, 20),
(@qid, @s2, 'q4', 'Daily temperature monitoring completed?', 'boolean', 1, 20),
(@qid, @s2, 'q5', 'Any stockouts this week?', 'boolean', 2, 20);

INSERT INTO performance_period (label, period_start, period_end) VALUES
('2021', '2021-01-01', '2021-12-31'),
('2022', '2022-01-01', '2022-12-31'),
('2023', '2023-01-01', '2023-12-31'),
('2024', '2024-01-01', '2024-12-31'),
('2025', '2025-01-01', '2025-12-31');

INSERT INTO course_catalogue (code, title, moodle_url, recommended_for, min_score, max_score) VALUES
('FIN-101', 'Financial Management Fundamentals', 'https://moodle.example.com/course/fin101', 'finance', 0, 79),
('ICT-201', 'Digital Security Essentials', 'https://moodle.example.com/course/ict201', 'ict', 0, 89),
('HRM-110', 'People Management Basics', 'https://moodle.example.com/course/hrm110', 'hrm', 0, 89),
('GEN-050', 'Customer Service Excellence', 'https://moodle.example.com/course/gen050', 'general_service', 0, 89),
('LEAD-300', 'Leadership and Team Nurturing', 'https://moodle.example.com/course/lead300', 'leadership_tn', 0, 94),
('SAFE-210', 'Security Awareness Refresher', 'https://moodle.example.com/course/safe210', 'security', 0, 94);
