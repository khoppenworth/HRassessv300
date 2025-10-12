<?php
require_once __DIR__ . '/config.php';

$_SESSION = [];
if (session_status() === PHP_SESSION_ACTIVE) {
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    setcookie('lang', '', time() - 3600, locale_cookie_path());
    header('Location: ' . url_for('login.php'));
}
exit;
?>