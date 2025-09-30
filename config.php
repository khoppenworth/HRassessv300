<?php
// config.php (enhanced)
declare(strict_types=1);
session_start();

define('DB_HOST','127.0.0.1');
define('DB_NAME','epss_v300');
define('DB_USER','epss_user');
define('DB_PASS','StrongPassword123!');
define('BASE_URL','/');

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    die("DB connection failed: " . $e->getMessage());
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $tok = $_POST['csrf'] ?? '';
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $tok)) {
            http_response_code(400); die('Invalid CSRF token');
        }
    }
}

function auth_required(array $roles = []): void {
    if (!isset($_SESSION['user'])) { header('Location: ' . BASE_URL . 'index.php'); exit; }
    if ($roles && !in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403); die('Forbidden');
    }
}
function current_user() { return $_SESSION['user'] ?? null; }

require_once __DIR__.'/i18n.php';

/** get_site_config(): fetch branding and contact settings (singleton row id=1) */
function get_site_config(PDO $pdo): array {
    $cfg = $pdo->query("SELECT * FROM site_config WHERE id=1")->fetch();
    if (!$cfg) {
        $pdo->exec("INSERT IGNORE INTO site_config (id,site_name,landing_text,address,contact,logo_path) VALUES (1,'EPSS Self-Assessment',NULL,NULL,NULL,NULL)");
        $cfg = $pdo->query("SELECT * FROM site_config WHERE id=1")->fetch();
    }
    return $cfg ?: [];
}
?>
