-- dummy_data_cleanup.sql: remove seeded demo users and their submissions
DELETE qr FROM questionnaire_response qr JOIN users u ON u.id = qr.user_id WHERE u.username LIKE 'demo_user_%';
DELETE FROM users WHERE username LIKE 'demo_user_%';
