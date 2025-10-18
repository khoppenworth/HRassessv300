-- dummy_data.sql: comprehensive demo dataset covering questionnaires, assignments, responses, and analytics
-- Demo accounts share a temporary password that must be reset on first login.
SET @password := '$2y$12$IQkYkVMIQE9G/dFkTcvObO1ekoYyOz2gk.d79KxQMOnPOrldv7drq';

-- Clean up previous demo dataset ------------------------------------------------
SET @demo_questionnaire := (SELECT id FROM questionnaire WHERE title = 'Demo Comprehensive Performance Review' LIMIT 1);

DELETE tr
FROM training_recommendation tr
JOIN questionnaire_response qr ON qr.id = tr.questionnaire_response_id
JOIN users u ON u.id = qr.user_id
WHERE u.username LIKE 'demo_%';

DELETE qri
FROM questionnaire_response_item qri
JOIN questionnaire_response qr ON qr.id = qri.response_id
JOIN users u ON u.id = qr.user_id
WHERE u.username LIKE 'demo_%';

DELETE FROM questionnaire_response WHERE questionnaire_id = @demo_questionnaire OR user_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');
DELETE FROM questionnaire_assignment WHERE questionnaire_id = @demo_questionnaire OR staff_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');
DELETE FROM questionnaire_work_function WHERE questionnaire_id = @demo_questionnaire;
DELETE qio
FROM questionnaire_item_option qio
JOIN questionnaire_item qi ON qi.id = qio.questionnaire_item_id
WHERE qi.questionnaire_id = @demo_questionnaire;
DELETE FROM questionnaire_item WHERE questionnaire_id = @demo_questionnaire;
DELETE FROM questionnaire_section WHERE questionnaire_id = @demo_questionnaire;
DELETE FROM questionnaire WHERE id = @demo_questionnaire;

DELETE FROM analytics_report_schedule WHERE created_by IN (SELECT id FROM users WHERE username LIKE 'demo_%');
DELETE FROM logs WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'demo_%');
DELETE FROM course_catalogue WHERE code LIKE 'DEMO-%';

DELETE FROM users WHERE username LIKE 'demo_%';

-- Insert demo users --------------------------------------------------------------
INSERT INTO users (username,password,role,full_name,email,gender,date_of_birth,phone,department,cadre,work_function,profile_completed,must_reset_password,account_status,language)
VALUES
('demo_admin', @password, 'admin', 'Demo System Administrator', 'demo.admin@example.com', 'female', '1980-01-01', '+251900000000', 'Executive Office', 'Administrator', 'leadership_tn', 1, 1, 'active', 'en'),
('demo_supervisor', @password, 'supervisor', 'Demo Performance Supervisor', 'demo.supervisor@example.com', 'male', '1982-02-02', '+251900000010', 'People & Culture', 'Manager', 'hrm', 1, 1, 'active', 'en'),
('demo_supervisor2', @password, 'supervisor', 'Demo Operations Supervisor', 'demo.supervisor2@example.com', 'female', '1984-03-03', '+251900000011', 'Operations', 'Manager', 'general_service', 1, 1, 'active', 'en'),
('demo_finance', @password, 'staff', 'Finance Demo', 'demo.finance@example.com', 'female', '1986-01-01', '+251910000001', 'Finance', 'Officer', 'finance', 1, 1, 'active', 'en'),
('demo_general_service', @password, 'staff', 'General Service Demo', 'demo.general@example.com', 'male', '1987-02-01', '+251910000002', 'General Services', 'Officer', 'general_service', 1, 1, 'active', 'en'),
('demo_hrm', @password, 'staff', 'HRM Demo', 'demo.hrm@example.com', 'female', '1988-03-01', '+251910000003', 'Human Resources', 'Specialist', 'hrm', 1, 1, 'active', 'en'),
('demo_ict', @password, 'staff', 'ICT Demo', 'demo.ict@example.com', 'male', '1989-04-01', '+251910000004', 'ICT', 'Specialist', 'ict', 1, 1, 'active', 'en'),
('demo_leadership', @password, 'staff', 'Leadership Demo', 'demo.leadership@example.com', 'female', '1985-05-01', '+251910000005', 'Leadership', 'Coordinator', 'leadership_tn', 1, 1, 'active', 'en'),
('demo_legal', @password, 'staff', 'Legal Service Demo', 'demo.legal@example.com', 'female', '1984-06-01', '+251910000006', 'Legal Service', 'Advisor', 'legal_service', 1, 1, 'active', 'en'),
('demo_pme', @password, 'staff', 'PME Demo', 'demo.pme@example.com', 'male', '1983-07-01', '+251910000007', 'Planning & Evaluation', 'Analyst', 'pme', 1, 1, 'active', 'en'),
('demo_quant', @password, 'staff', 'Quantification Demo', 'demo.quant@example.com', 'female', '1982-08-01', '+251910000008', 'Quantification', 'Analyst', 'quantification', 1, 1, 'active', 'en'),
('demo_records', @password, 'staff', 'Records Demo', 'demo.records@example.com', 'female', '1981-09-01', '+251910000009', 'Records Management', 'Officer', 'records_documentation', 1, 1, 'active', 'en'),
('demo_security_driver', @password, 'staff', 'Security Driver Demo', 'demo.secdriver@example.com', 'male', '1980-10-01', '+251910000010', 'Security & Driver', 'Supervisor', 'security_driver', 1, 1, 'active', 'en'),
('demo_security', @password, 'staff', 'Security Demo', 'demo.security@example.com', 'male', '1979-11-01', '+251910000011', 'Security', 'Officer', 'security', 1, 1, 'active', 'en'),
('demo_tmd', @password, 'staff', 'TMD Demo', 'demo.tmd@example.com', 'female', '1978-12-01', '+251910000012', 'TMD', 'Officer', 'tmd', 1, 1, 'active', 'en'),
('demo_wim', @password, 'staff', 'WIM Demo', 'demo.wim@example.com', 'female', '1977-01-15', '+251910000013', 'WIM', 'Officer', 'wim', 1, 1, 'active', 'en'),
('demo_cmd', @password, 'staff', 'CMD Demo', 'demo.cmd@example.com', 'male', '1976-02-15', '+251910000014', 'CMD', 'Officer', 'cmd', 1, 1, 'active', 'en'),
('demo_comm', @password, 'staff', 'Communication Demo', 'demo.communication@example.com', 'female', '1975-03-15', '+251910000015', 'Communication', 'Officer', 'communication', 1, 1, 'active', 'en'),
('demo_dfm', @password, 'staff', 'DFM Demo', 'demo.dfm@example.com', 'male', '1974-04-15', '+251910000016', 'DFM', 'Officer', 'dfm', 1, 1, 'active', 'en'),
('demo_driver', @password, 'staff', 'Driver Demo', 'demo.driver@example.com', 'male', '1973-05-15', '+251910000017', 'Transport', 'Driver', 'driver', 1, 1, 'active', 'en'),
('demo_ethics', @password, 'staff', 'Ethics Demo', 'demo.ethics@example.com', 'female', '1972-06-15', '+251910000018', 'Ethics', 'Advisor', 'ethics', 1, 1, 'active', 'en');

SET @demo_admin_id := (SELECT id FROM users WHERE username = 'demo_admin');
SET @demo_supervisor_id := (SELECT id FROM users WHERE username = 'demo_supervisor');
SET @demo_supervisor2_id := (SELECT id FROM users WHERE username = 'demo_supervisor2');

UPDATE users SET approved_by = @demo_admin_id, approved_at = NOW() WHERE username LIKE 'demo_%' AND role = 'staff';
UPDATE users SET first_login_at = DATE_SUB(NOW(), INTERVAL 10 DAY) WHERE username IN ('demo_admin','demo_supervisor','demo_supervisor2');

-- Activity log entries -----------------------------------------------------------
INSERT INTO logs (user_id, action, meta)
VALUES
(@demo_admin_id, 'login', '{"ip":"10.0.0.5","agent":"demo-seed"}'),
(@demo_supervisor_id, 'login', '{"ip":"10.0.0.6","agent":"demo-seed"}'),
(@demo_supervisor2_id, 'login', '{"ip":"10.0.0.7","agent":"demo-seed"}');

INSERT INTO logs (user_id, action, meta)
SELECT u.id, 'profile_update', CONCAT('{"status":"completed","department":"', u.department, '"}')
FROM users u
WHERE u.username LIKE 'demo_%' AND u.role = 'staff';

-- Extended learning catalogue ----------------------------------------------------
INSERT INTO course_catalogue (code, title, moodle_url, recommended_for, min_score, max_score)
VALUES
('DEMO-FIN-301', 'Advanced Budget Controls', 'https://moodle.example.com/course/fin301', 'finance', 0, 79),
('DEMO-ICT-205', 'Automation & Scripting', 'https://moodle.example.com/course/ict205', 'ict', 0, 84),
('DEMO-HRM-260', 'Coaching Conversations', 'https://moodle.example.com/course/hrm260', 'hrm', 0, 89),
('DEMO-GEN-120', 'Customer Care Excellence', 'https://moodle.example.com/course/gen120', 'general_service', 0, 89),
('DEMO-SEC-180', 'Security Incident Response', 'https://moodle.example.com/course/sec180', 'security', 0, 94),
('DEMO-LEAD-400', 'Leading Change Initiatives', 'https://moodle.example.com/course/lead400', 'leadership_tn', 0, 96);

-- Demo questionnaire -------------------------------------------------------------
INSERT INTO questionnaire (title, description)
VALUES ('Demo Comprehensive Performance Review', 'Rich dataset covering all work functions.');
SET @demo_qid := LAST_INSERT_ID();

INSERT INTO questionnaire_section (questionnaire_id, title, description, order_index)
VALUES
(@demo_qid, 'Core Competencies', 'Baseline capability and compliance checks.', 1),
(@demo_qid, 'Goals & Achievements', 'Narrative and objective goal tracking.', 2),
(@demo_qid, 'Development Planning', 'Future development focus areas.', 3);

SET @section_core := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @demo_qid AND order_index = 1);
SET @section_goals := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @demo_qid AND order_index = 2);
SET @section_dev := (SELECT id FROM questionnaire_section WHERE questionnaire_id = @demo_qid AND order_index = 3);

INSERT INTO questionnaire_item (questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple, is_required)
VALUES
(@demo_qid, @section_core, 'core_1', 'Overall performance against annual objectives', 'likert', 1, 25, 0, 1),
(@demo_qid, @section_core, 'core_2', 'Completed mandatory compliance trainings', 'boolean', 2, 20, 0, 1),
(@demo_qid, @section_goals, 'goal_1', 'Summarize the most significant accomplishment this period', 'text', 1, 15, 0, 1),
(@demo_qid, @section_goals, 'goal_2', 'Select achieved stretch goals', 'choice', 2, 10, 1, 0),
(@demo_qid, @section_dev, 'dev_1', 'Describe a development need for the next review', 'textarea', 1, 15, 0, 0),
(@demo_qid, @section_dev, 'dev_2', 'Readiness for additional responsibilities', 'likert', 2, 15, 0, 0);

INSERT INTO questionnaire_item_option (questionnaire_item_id, value, order_index)
SELECT qi.id, opt.value, opt.order_index
FROM questionnaire_item qi
JOIN (
    SELECT 'core_1' AS linkId, '5 - Outstanding' AS value, 1 AS order_index UNION ALL
    SELECT 'core_1', '4 - Exceeds Expectations', 2 UNION ALL
    SELECT 'core_1', '3 - Meets Expectations', 3 UNION ALL
    SELECT 'core_1', '2 - Needs Improvement', 4 UNION ALL
    SELECT 'core_1', '1 - Unsatisfactory', 5 UNION ALL
    SELECT 'dev_2', '5 - Ready to Lead Projects', 1 UNION ALL
    SELECT 'dev_2', '4 - Ready for Advanced Tasks', 2 UNION ALL
    SELECT 'dev_2', '3 - Solid in Current Role', 3 UNION ALL
    SELECT 'dev_2', '2 - Requires Coaching', 4 UNION ALL
    SELECT 'dev_2', '1 - Significant Development Needed', 5 UNION ALL
    SELECT 'goal_2', 'Cross-functional project delivery', 1 UNION ALL
    SELECT 'goal_2', 'Mentored junior colleagues', 2 UNION ALL
    SELECT 'goal_2', 'Implemented process automation', 3 UNION ALL
    SELECT 'goal_2', 'Improved customer satisfaction scores', 4 UNION ALL
    SELECT 'goal_2', 'Reduced compliance incidents', 5
) AS opt ON opt.linkId = qi.linkId
WHERE qi.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_work_function (questionnaire_id, work_function)
SELECT @demo_qid, wf
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
) AS functions;

-- Assign questionnaire to demo staff --------------------------------------------
INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by, assigned_at)
SELECT u.id,
       @demo_qid,
       CASE WHEN u.work_function IN ('finance','hrm','legal_service','pme','quantification') THEN @demo_supervisor_id ELSE @demo_supervisor2_id END,
       DATE_SUB(NOW(), INTERVAL (u.id % 12) DAY)
FROM users u
WHERE u.username LIKE 'demo_%' AND u.role = 'staff';

-- Responses for 2024 (approved) and 2025 (submitted) ------------------------------
INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at)
SELECT u.id,
       @demo_qid,
       pp.id,
       CASE WHEN pp.label = '2024' THEN 'approved' ELSE 'submitted' END,
       CASE
           WHEN pp.label = '2024' THEN 70 + (u.id % 6) * 5
           ELSE 60 + (u.id % 5) * 4
       END,
       CASE WHEN pp.label = '2024' THEN CASE WHEN u.work_function IN ('finance','hrm','legal_service','pme','quantification') THEN @demo_supervisor_id ELSE @demo_supervisor2_id END ELSE NULL END,
       CASE WHEN pp.label = '2024' THEN DATE_SUB(NOW(), INTERVAL (u.id % 9 + 1) DAY) ELSE NULL END,
       CASE WHEN pp.label = '2024' THEN CONCAT('Reviewed by supervisor for ', u.department) ELSE NULL END,
       DATE_ADD(pp.period_start, INTERVAL (u.id % 20) DAY)
FROM users u
JOIN performance_period pp ON pp.label IN ('2024','2025')
WHERE u.username LIKE 'demo_%' AND u.role = 'staff';

-- Populate questionnaire response items -----------------------------------------
INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'core_1',
       CASE
           WHEN pp.label = '2024' AND u.work_function IN ('finance','hrm','legal_service') THEN '[{"valueInteger":5,"valueString":"5 - Outstanding"}]'
           WHEN pp.label = '2024' AND u.work_function IN ('ict','communication','tmd','wim') THEN '[{"valueInteger":4,"valueString":"4 - Exceeds Expectations"}]'
           WHEN pp.label = '2024' THEN '[{"valueInteger":3,"valueString":"3 - Meets Expectations"}]'
           WHEN pp.label = '2025' THEN '[{"valueInteger":3,"valueString":"3 - Meets Expectations"}]'
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'core_2',
       CASE
           WHEN pp.label = '2024' THEN '[{"valueBoolean":true}]'
           ELSE CASE WHEN u.id % 3 = 0 THEN '[{"valueBoolean":false}]' ELSE '[{"valueBoolean":true}]' END
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'goal_1',
       CONCAT('[{"valueString":"',
           CASE
               WHEN pp.label = '2024' THEN 'Delivered key initiative for '
               ELSE 'On track initiative for '
           END,
           u.department,
           '"}]')
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'goal_2',
       CASE
           WHEN u.work_function IN ('finance','quantification') THEN '[{"valueString":"Reduced compliance incidents"},{"valueString":"Implemented process automation"}]'
           WHEN u.work_function IN ('ict','communication') THEN '[{"valueString":"Implemented process automation"},{"valueString":"Cross-functional project delivery"}]'
           WHEN u.work_function IN ('security','security_driver','driver') THEN '[{"valueString":"Reduced compliance incidents"}]'
           ELSE '[{"valueString":"Mentored junior colleagues"},{"valueString":"Improved customer satisfaction scores"}]'
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'dev_1',
       CONCAT('[{"valueString":"Plan to enhance ', u.work_function, ' capabilities next quarter."}]')
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
WHERE qr.questionnaire_id = @demo_qid;

INSERT INTO questionnaire_response_item (response_id, linkId, answer)
SELECT qr.id,
       'dev_2',
       CASE
           WHEN pp.label = '2024' AND u.work_function IN ('leadership_tn','legal_service','cmd') THEN '[{"valueInteger":5,"valueString":"5 - Ready to Lead Projects"}]'
           WHEN pp.label = '2024' AND u.work_function IN ('ict','communication','pme','quantification') THEN '[{"valueInteger":4,"valueString":"4 - Ready for Advanced Tasks"}]'
           WHEN pp.label = '2024' THEN '[{"valueInteger":3,"valueString":"3 - Solid in Current Role"}]'
           WHEN pp.label = '2025' AND u.id % 4 = 0 THEN '[{"valueInteger":2,"valueString":"2 - Requires Coaching"}]'
           ELSE '[{"valueInteger":3,"valueString":"3 - Solid in Current Role"}]'
       END
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
WHERE qr.questionnaire_id = @demo_qid;

-- Training recommendations based on scores ---------------------------------------
INSERT INTO training_recommendation (questionnaire_response_id, course_id, recommendation_reason)
SELECT qr.id,
       cc.id,
       CONCAT('Score ', qr.score, ' suggests focus on ', cc.title)
FROM questionnaire_response qr
JOIN users u ON u.id = qr.user_id
JOIN performance_period pp ON pp.id = qr.performance_period_id
JOIN course_catalogue cc ON cc.code = CASE
    WHEN u.work_function = 'finance' THEN 'DEMO-FIN-301'
    WHEN u.work_function = 'ict' THEN 'DEMO-ICT-205'
    WHEN u.work_function = 'hrm' THEN 'DEMO-HRM-260'
    WHEN u.work_function = 'security' THEN 'DEMO-SEC-180'
    WHEN u.work_function = 'leadership_tn' THEN 'DEMO-LEAD-400'
    ELSE 'DEMO-GEN-120'
END
WHERE qr.questionnaire_id = @demo_qid AND pp.label = '2024' AND qr.score < 90;

-- Analytics report schedules -----------------------------------------------------
INSERT INTO analytics_report_schedule (recipients, frequency, next_run_at, last_run_at, created_by, questionnaire_id, include_details, active)
VALUES
('demo.supervisor@example.com,demo.supervisor2@example.com', 'weekly', DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 5 DAY), @demo_admin_id, @demo_qid, 1, 1),
('executive@example.com', 'monthly', DATE_ADD(NOW(), INTERVAL 15 DAY), NULL, @demo_admin_id, @demo_qid, 0, 1);

-- End of comprehensive demo dataset ---------------------------------------------
