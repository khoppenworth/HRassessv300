<?php
require_once __DIR__ . '/config.php';
auth_required();
refresh_current_user($pdo);
require_profile_completion($pdo);

$redirectTarget = url_for('my_performance.php');
header('Location: ' . $redirectTarget);
exit;
