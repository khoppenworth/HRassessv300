
-- Enhanced init.sql (adds site_config and weight_percent)
DROP TABLE IF EXISTS questionnaire_response_item;
DROP TABLE IF EXISTS questionnaire_response;
DROP TABLE IF EXISTS questionnaire_item;
DROP TABLE IF EXISTS questionnaire_section;
DROP TABLE IF EXISTS questionnaire;
DROP TABLE IF EXISTS logs;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS site_config;

CREATE TABLE site_config (
  id INT PRIMARY KEY,
  site_name VARCHAR(200) NULL,
  landing_text TEXT NULL,
  address VARCHAR(255) NULL,
  contact VARCHAR(255) NULL,
  logo_path VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin','supervisor','staff') NOT NULL DEFAULT 'staff',
  full_name VARCHAR(200) NULL,
  email VARCHAR(200) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
  type ENUM('text','textarea','boolean') NOT NULL DEFAULT 'text',
  order_index INT NOT NULL DEFAULT 0,
  weight_percent INT NOT NULL DEFAULT 0,
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE,
  FOREIGN KEY (section_id) REFERENCES questionnaire_section(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_response (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  questionnaire_id INT NOT NULL,
  status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
  score INT NULL, -- percentage 0..100
  reviewed_by INT NULL,
  reviewed_at DATETIME NULL,
  review_comment TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (questionnaire_id) REFERENCES questionnaire(id) ON DELETE CASCADE,
  FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE questionnaire_response_item (
  id INT AUTO_INCREMENT PRIMARY KEY,
  response_id INT NOT NULL,
  linkId VARCHAR(64) NOT NULL,
  answer JSON NOT NULL,
  FOREIGN KEY (response_id) REFERENCES questionnaire_response(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO site_config (id, site_name) VALUES (1, 'EPSS Self-Assessment');

-- default users (bcrypt hashes should be set during runtime; using demo placeholder hashes)
INSERT INTO users (username,password,role,full_name,email) VALUES
('admin', '$2y$10$9VQpYQ6k1cP6m0Wkq6YJTe1cW5Hc0yQ8Gm3qGf0j6Qj0M4jY3P4wW', 'admin', 'System Admin', 'admin@example.com'),
('super', '$2y$10$Pj9m0H6b8K2ZyQe7p0k1TOeGq1bqfP3QO3Y6b5g1YQb1J2lL8mJxC', 'supervisor', 'Default Supervisor', 'super@example.com'),
('staff', '$2y$10$Pj9m0H6b8K2ZyQe7p0k1TOeGq1bqfP3QO3Y6b5g1YQb1J2lL8mJxC', 'staff', 'Sample Staff', 'staff@example.com');

-- sample questionnaire with weights
INSERT INTO questionnaire (title, description) VALUES ('Baseline Staff Self-Assessment', 'Initial EPSS self-assessment');
SET @qid = LAST_INSERT_ID();
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
