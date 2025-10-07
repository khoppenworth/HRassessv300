<?php
require_once __DIR__.'/config.php';
$lang = $_GET['lang'] ?? 'en';
if (!in_array($lang, ['en','am','fr'], true)) {
    $lang = 'en';
}
$_SESSION['lang'] = $lang;
if (!empty($_SESSION['user']['id'])) {
    $stmt = $pdo->prepare('UPDATE users SET language = ? WHERE id = ?');
    $stmt->execute([$lang, $_SESSION['user']['id']]);
    refresh_current_user($pdo);
}
header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
exit;
?>