<?php
// config.php (enhanced)
declare(strict_types=1);
session_start();

const WORK_FUNCTIONS = [
    'finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification',
    'records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics'
];

const WORK_FUNCTION_LABELS = [
    'finance' => 'Finance',
    'general_service' => 'General Service',
    'hrm' => 'HRM',
    'ict' => 'ICT',
    'leadership_tn' => 'Leadership TN',
    'legal_service' => 'Legal Service',
    'pme' => 'PME',
    'quantification' => 'Quantification',
    'records_documentation' => 'Records & Documentation',
    'security_driver' => 'Security & Driver',
    'security' => 'Security',
    'tmd' => 'TMD',
    'wim' => 'WIM',
    'cmd' => 'CMD',
    'communication' => 'Communication',
    'dfm' => 'DFM',
    'driver' => 'Driver',
    'ethics' => 'Ethics',
];

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

function refresh_current_user(PDO $pdo): void {
    if (!isset($_SESSION['user']['id'])) { return; }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user']['id']]);
    if ($row = $stmt->fetch()) {
        $_SESSION['user'] = $row;
    }
}

function auth_required(array $roles = []): void {
    if (!isset($_SESSION['user'])) { header('Location: ' . BASE_URL . 'index.php'); exit; }
    if ($roles && !in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403); die('Forbidden');
    }
}
function current_user() { return $_SESSION['user'] ?? null; }

function require_profile_completion(PDO $pdo, string $redirect = 'profile.php'): void {
    if (!isset($_SESSION['user']['id'])) { return; }
    if (($_SESSION['user']['profile_completed'] ?? 0) == 1) { return; }
    if (basename($_SERVER['SCRIPT_NAME']) === basename($redirect)) { return; }
    header('Location: ' . BASE_URL . $redirect);
    exit;
}

require_once __DIR__.'/i18n.php';

/** get_site_config(): fetch branding and contact settings (singleton row id=1) */
function get_site_config(PDO $pdo): array {
    $defaults = [
        'id' => 1,
        'site_name' => 'My Performance',
        'landing_text' => null,
        'address' => null,
        'contact' => null,
        'logo_path' => null,
    ];

    try {
        $cfg = $pdo->query("SELECT * FROM site_config WHERE id=1")->fetch();
    } catch (PDOException $e) {
        $message = $e->getMessage();
        $isMissingTable = strpos($message, '42S02') !== false || stripos($message, 'Base table or view not found') !== false;
        if ($isMissingTable) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_config (
                id INT PRIMARY KEY,
                site_name VARCHAR(200) NULL,
                landing_text TEXT NULL,
                address VARCHAR(255) NULL,
                contact VARCHAR(255) NULL,
                logo_path VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("INSERT IGNORE INTO site_config (id,site_name,landing_text,address,contact,logo_path) VALUES (1,'My Performance',NULL,NULL,NULL,NULL)");
            $cfg = $pdo->query("SELECT * FROM site_config WHERE id=1")->fetch();
        } else {
            error_log('get_site_config failed: ' . $message);
            return $defaults;
        }
    }

    if (!$cfg) {
        $pdo->exec("INSERT IGNORE INTO site_config (id,site_name,landing_text,address,contact,logo_path) VALUES (1,'My Performance',NULL,NULL,NULL,NULL)");
        $cfg = $pdo->query("SELECT * FROM site_config WHERE id=1")->fetch();
    }

    return array_merge($defaults, $cfg ?: []);
}
?>
