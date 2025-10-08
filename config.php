<?php
declare(strict_types=1);

if (!defined('APP_BOOTSTRAPPED')) {
    define('APP_BOOTSTRAPPED', true);

    $appDebug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN);
    ini_set('display_errors', $appDebug ? '1' : '0');
    error_reporting(E_ALL);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    define('APP_DEBUG', $appDebug);

    define('BASE_PATH', __DIR__);

    $baseUrlEnv = getenv('BASE_URL') ?: '/';
    $normalizedBaseUrl = rtrim($baseUrlEnv, "/\/");
    define('BASE_URL', ($normalizedBaseUrl === '') ? '/' : $normalizedBaseUrl . '/');

    require_once __DIR__ . '/i18n.php';
    require_once __DIR__ . '/lib/path.php';

    $locale = ensure_locale();
    if (!isset($_SESSION['lang']) || $_SESSION['lang'] !== $locale) {
        $_SESSION['lang'] = $locale;
    }

    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'epss_v300';
    $dbUser = getenv('DB_USER') ?: 'epss_user';
    $dbPass = getenv('DB_PASS') ?: 'StrongPassword123!';

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    } catch (PDOException $e) {
        $friendly = 'Unable to connect to the application database. Please try again later or contact support.';
        error_log('DB connection failed: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $friendly . PHP_EOL);
            exit(1);
        }
        http_response_code(500);
        if ($appDebug) {
            $friendly .= '<br>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Database error</title></head><body>';
        echo '<h1>Service unavailable</h1><p>' . $friendly . '</p>';
        echo '</body></html>';
        exit;
    }
}

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
