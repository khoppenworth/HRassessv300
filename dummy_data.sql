-- dummy_data.sql: seed demo users per work function and five years of submissions
SET @password := '$2y$10$Pj9m0H6b8K2ZyQe7p0k1TOeGq1bqfP3QO3Y6b5g1YQb1J2lL8mJxC';

DELETE FROM questionnaire_response
WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');
DELETE FROM users WHERE username LIKE 'demo_%';

INSERT INTO users (username,password,role,full_name,email,gender,date_of_birth,phone,department,cadre,work_function,profile_completed)
VALUES
('demo_finance', @password, 'staff', 'Finance Demo', 'demo.finance@example.com', 'female', '1986-01-01', '+251910000001', 'Finance', 'Officer', 'finance', 1),
('demo_general_service', @password, 'staff', 'General Service Demo', 'demo.general@example.com', 'male', '1987-02-01', '+251910000002', 'General Services', 'Officer', 'general_service', 1),
('demo_hrm', @password, 'staff', 'HRM Demo', 'demo.hrm@example.com', 'female', '1988-03-01', '+251910000003', 'Human Resources', 'Specialist', 'hrm', 1),
('demo_ict', @password, 'staff', 'ICT Demo', 'demo.ict@example.com', 'male', '1989-04-01', '+251910000004', 'ICT', 'Specialist', 'ict', 1),
('demo_leadership', @password, 'staff', 'Leadership Demo', 'demo.leadership@example.com', 'female', '1985-05-01', '+251910000005', 'Leadership', 'Coordinator', 'leadership_tn', 1),
('demo_legal', @password, 'staff', 'Legal Service Demo', 'demo.legal@example.com', 'female', '1984-06-01', '+251910000006', 'Legal Service', 'Advisor', 'legal_service', 1),
('demo_pme', @password, 'staff', 'PME Demo', 'demo.pme@example.com', 'male', '1983-07-01', '+251910000007', 'Planning & Evaluation', 'Analyst', 'pme', 1),
('demo_quant', @password, 'staff', 'Quantification Demo', 'demo.quant@example.com', 'female', '1982-08-01', '+251910000008', 'Quantification', 'Analyst', 'quantification', 1),
('demo_records', @password, 'staff', 'Records Demo', 'demo.records@example.com', 'female', '1981-09-01', '+251910000009', 'Records Management', 'Officer', 'records_documentation', 1),
('demo_security_driver', @password, 'staff', 'Security Driver Demo', 'demo.secdriver@example.com', 'male', '1980-10-01', '+251910000010', 'Security & Driver', 'Supervisor', 'security_driver', 1),
('demo_security', @password, 'staff', 'Security Demo', 'demo.security@example.com', 'male', '1979-11-01', '+251910000011', 'Security', 'Officer', 'security', 1),
('demo_tmd', @password, 'staff', 'TMD Demo', 'demo.tmd@example.com', 'female', '1978-12-01', '+251910000012', 'TMD', 'Officer', 'tmd', 1),
('demo_wim', @password, 'staff', 'WIM Demo', 'demo.wim@example.com', 'female', '1977-01-15', '+251910000013', 'WIM', 'Officer', 'wim', 1),
('demo_cmd', @password, 'staff', 'CMD Demo', 'demo.cmd@example.com', 'male', '1976-02-15', '+251910000014', 'CMD', 'Officer', 'cmd', 1),
('demo_comm', @password, 'staff', 'Communication Demo', 'demo.communication@example.com', 'female', '1975-03-15', '+251910000015', 'Communication', 'Officer', 'communication', 1),
('demo_dfm', @password, 'staff', 'DFM Demo', 'demo.dfm@example.com', 'male', '1974-04-15', '+251910000016', 'DFM', 'Officer', 'dfm', 1),
('demo_driver', @password, 'staff', 'Driver Demo', 'demo.driver@example.com', 'male', '1973-05-15', '+251910000017', 'Transport', 'Driver', 'driver', 1),
('demo_ethics', @password, 'staff', 'Ethics Demo', 'demo.ethics@example.com', 'female', '1972-06-15', '+251910000018', 'Ethics', 'Advisor', 'ethics', 1);

SET @demo_q := (SELECT id FROM questionnaire ORDER BY id LIMIT 1);

INSERT IGNORE INTO questionnaire_work_function (questionnaire_id, work_function)
SELECT @demo_q, wf
FROM (
  SELECT 'finance' AS wf UNION ALL
  SELECT 'general_service' UNION ALL
  SELECT 'hrm' UNION ALL
  SELECT 'ict' UNION ALL
  SELECT 'leadership_tn' UNION ALL
  SELECT 'legal_service' UNION ALL
  SELECT 'pme' UNION ALL
  SELECT 'quantification' UNION ALL
  SELECT 'records_documentation' UNION ALL
  SELECT 'security_driver' UNION ALL
  SELECT 'security' UNION ALL
  SELECT 'tmd' UNION ALL
  SELECT 'wim' UNION ALL
  SELECT 'cmd' UNION ALL
  SELECT 'communication' UNION ALL
  SELECT 'dfm' UNION ALL
  SELECT 'driver' UNION ALL
  SELECT 'ethics'
) AS functions
WHERE @demo_q IS NOT NULL;

INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, created_at)
SELECT
  u.id,
  @demo_q,
  pp.id,
  'submitted',
  ROUND(55 + RAND(u.id * 13 + pp.id) * 45),
  DATE_ADD(pp.period_start, INTERVAL ((u.id + pp.id) % 28) DAY)
FROM users u
JOIN performance_period pp ON pp.label IN ('2021','2022','2023','2024','2025')
WHERE u.username LIKE 'demo_%' AND @demo_q IS NOT NULL;
