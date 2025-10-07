
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
INSERT IGNORE INTO site_config (id, site_name) VALUES (1, 'My Performance');

ALTER TABLE users
  ADD COLUMN gender ENUM('female','male','other','prefer_not_say') NULL AFTER email,
  ADD COLUMN date_of_birth DATE NULL AFTER gender,
  ADD COLUMN phone VARCHAR(50) NULL AFTER date_of_birth,
  ADD COLUMN department VARCHAR(150) NULL AFTER phone,
  ADD COLUMN cadre VARCHAR(150) NULL AFTER department,
  ADD COLUMN work_function ENUM('finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification','records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics') NOT NULL DEFAULT 'general_service' AFTER cadre,
  ADD COLUMN profile_completed TINYINT(1) NOT NULL DEFAULT 0 AFTER work_function,
  ADD COLUMN language VARCHAR(5) NOT NULL DEFAULT 'en' AFTER profile_completed,
  ADD COLUMN first_login_at DATETIME NULL AFTER language;

CREATE TABLE IF NOT EXISTS performance_period (
  id INT AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(50) NOT NULL,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  UNIQUE KEY uniq_period_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO performance_period (id, label, period_start, period_end) VALUES
(1,'2021','2021-01-01','2021-12-31'),
(2,'2022','2022-01-01','2022-12-31'),
(3,'2023','2023-01-01','2023-12-31'),
(4,'2024','2024-01-01','2024-12-31'),
(5,'2025','2025-01-01','2025-12-31');

ALTER TABLE questionnaire_response
  ADD COLUMN performance_period_id INT NOT NULL DEFAULT 1 AFTER questionnaire_id,
  ADD CONSTRAINT fk_qr_period FOREIGN KEY (performance_period_id) REFERENCES performance_period(id) ON DELETE RESTRICT,
  ADD UNIQUE KEY uniq_user_questionnaire_period (user_id, questionnaire_id, performance_period_id);

UPDATE questionnaire_response SET performance_period_id = 1 WHERE performance_period_id IS NULL;

CREATE TABLE IF NOT EXISTS course_catalogue (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  moodle_url VARCHAR(255) NULL,
  recommended_for ENUM('finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification','records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics') NOT NULL,
  min_score INT NOT NULL DEFAULT 0,
  max_score INT NOT NULL DEFAULT 99,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS training_recommendation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  questionnaire_response_id INT NOT NULL,
  course_id INT NOT NULL,
  recommendation_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (questionnaire_response_id) REFERENCES questionnaire_response(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES course_catalogue(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS questionnaire_work_function (
  questionnaire_id INT NOT NULL,
  work_function ENUM('finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification','records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics') NOT NULL,
  PRIMARY KEY (questionnaire_id, work_function),
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
