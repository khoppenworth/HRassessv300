-- dummy_data.sql: seed 10 demo users and 100 submissions between 2021 and 2025
SET @password := '$2y$10$Pj9m0H6b8K2ZyQe7p0k1TOeGq1bqfP3QO3Y6b5g1YQb1J2lL8mJxC';
DELETE FROM questionnaire_response WHERE user_id IN (SELECT id FROM users WHERE username LIKE 'demo_user_%');
DELETE FROM users WHERE username LIKE 'demo_user_%';

INSERT INTO users (username,password,role,full_name,email,gender,date_of_birth,phone,department,cadre,work_function,profile_completed)
VALUES
('demo_user_01',@password,'staff','Demo User 01','demo01@example.com','female','1990-01-01','+251900000001','Pharmacy','Officer','general_service',1),
('demo_user_02',@password,'staff','Demo User 02','demo02@example.com','male','1989-02-01','+251900000002','Finance','Officer','finance',1),
('demo_user_03',@password,'staff','Demo User 03','demo03@example.com','female','1991-03-01','+251900000003','ICT','Specialist','ict',1),
('demo_user_04',@password,'staff','Demo User 04','demo04@example.com','female','1992-04-01','+251900000004','HR','Officer','hrm',1),
('demo_user_05',@password,'staff','Demo User 05','demo05@example.com','male','1985-05-01','+251900000005','Leadership','Coordinator','leadership_tn',1),
('demo_user_06',@password,'staff','Demo User 06','demo06@example.com','male','1988-06-01','+251900000006','Security','Guard','security',1),
('demo_user_07',@password,'staff','Demo User 07','demo07@example.com','female','1993-07-01','+251900000007','Records','Officer','records_documentation',1),
('demo_user_08',@password,'staff','Demo User 08','demo08@example.com','female','1994-08-01','+251900000008','Quantification','Analyst','quantification',1),
('demo_user_09',@password,'staff','Demo User 09','demo09@example.com','male','1986-09-01','+251900000009','Transport','Driver','driver',1),
('demo_user_10',@password,'staff','Demo User 10','demo10@example.com','female','1987-10-01','+251900000010','Ethics','Advisor','ethics',1);

SET @demo_q := (SELECT id FROM questionnaire LIMIT 1);
WITH RECURSIVE seq AS (
  SELECT 0 AS n
  UNION ALL
  SELECT n+1 FROM seq WHERE n < 99
)
INSERT INTO questionnaire_response (user_id, questionnaire_id, performance_period_id, status, score, created_at)
SELECT
  (SELECT id FROM users WHERE username = CONCAT('demo_user_', LPAD((n % 10) + 1, 2, '0'))),
  @demo_q,
  CASE n % 5
    WHEN 0 THEN (SELECT id FROM performance_period WHERE label='2021')
    WHEN 1 THEN (SELECT id FROM performance_period WHERE label='2022')
    WHEN 2 THEN (SELECT id FROM performance_period WHERE label='2023')
    WHEN 3 THEN (SELECT id FROM performance_period WHERE label='2024')
    ELSE (SELECT id FROM performance_period WHERE label='2025')
  END,
  'submitted',
  FLOOR(60 + RAND(n)*40),
  DATE_ADD('2021-01-01', INTERVAL n DAY)
FROM seq;
