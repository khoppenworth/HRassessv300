<?php
require_once __DIR__ . '/config.php';
$requested = $_GET['lang'] ?? 'en';
$lang = resolve_locale($requested);
$_SESSION['lang'] = $lang;
ensure_locale();
if (!empty($_SESSION['user']['id'])) {
    $stmt = $pdo->prepare('UPDATE users SET language = ? WHERE id = ?');
    $stmt->execute([$lang, $_SESSION['user']['id']]);
    refresh_current_user($pdo);
}
$redirect = $_SERVER['HTTP_REFERER'] ?? '';
if ($redirect !== '') {
    $parts = parse_url($redirect);
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!empty($parts['host']) && strcasecmp($parts['host'], $host) !== 0) {
        $redirect = '';
    }
}
if ($redirect === '') {
    $redirect = url_for('dashboard.php');
}
header('Location: ' . $redirect);
exit;
?>
