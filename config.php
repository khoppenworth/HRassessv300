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
    require_once __DIR__ . '/lib/security.php';

    $locale = ensure_locale();
    if (!isset($_SESSION['lang']) || $_SESSION['lang'] !== $locale) {
        $_SESSION['lang'] = $locale;
    }

    apply_security_headers($appDebug);

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
function request_csrf_token(): string {
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (isset($_POST['csrf'])) {
        return (string)$_POST['csrf'];
    }
    if (isset($_GET['csrf'])) {
        return (string)$_GET['csrf'];
    }
    return '';
}

function csrf_check(): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
        return;
    }
    $token = request_csrf_token();
    if ($token === '' || !isset($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $token)) {
        http_response_code(400);
        die('Invalid CSRF token');
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

function ensure_site_config_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_config (
        id INT PRIMARY KEY,
        site_name VARCHAR(200) NULL,
        landing_text TEXT NULL,
        address VARCHAR(255) NULL,
        contact VARCHAR(255) NULL,
        logo_path VARCHAR(255) NULL,
        footer_org_name VARCHAR(255) NULL,
        footer_org_short VARCHAR(100) NULL,
        footer_website_label VARCHAR(255) NULL,
        footer_website_url VARCHAR(255) NULL,
        footer_email VARCHAR(255) NULL,
        footer_phone VARCHAR(255) NULL,
        footer_hotline_label VARCHAR(255) NULL,
        footer_hotline_number VARCHAR(50) NULL,
        footer_rights VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $existing = [];
    $columns = $pdo->query('SHOW COLUMNS FROM site_config');
    if ($columns) {
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            if (isset($col['Field'])) {
                $existing[$col['Field']] = true;
            }
        }
    }

    $schema = [
        'site_name' => 'ALTER TABLE site_config ADD COLUMN site_name VARCHAR(200) NULL',
        'landing_text' => 'ALTER TABLE site_config ADD COLUMN landing_text TEXT NULL',
        'address' => 'ALTER TABLE site_config ADD COLUMN address VARCHAR(255) NULL',
        'contact' => 'ALTER TABLE site_config ADD COLUMN contact VARCHAR(255) NULL',
        'logo_path' => 'ALTER TABLE site_config ADD COLUMN logo_path VARCHAR(255) NULL',
        'footer_org_name' => 'ALTER TABLE site_config ADD COLUMN footer_org_name VARCHAR(255) NULL',
        'footer_org_short' => 'ALTER TABLE site_config ADD COLUMN footer_org_short VARCHAR(100) NULL',
        'footer_website_label' => 'ALTER TABLE site_config ADD COLUMN footer_website_label VARCHAR(255) NULL',
        'footer_website_url' => 'ALTER TABLE site_config ADD COLUMN footer_website_url VARCHAR(255) NULL',
        'footer_email' => 'ALTER TABLE site_config ADD COLUMN footer_email VARCHAR(255) NULL',
        'footer_phone' => 'ALTER TABLE site_config ADD COLUMN footer_phone VARCHAR(255) NULL',
        'footer_hotline_label' => 'ALTER TABLE site_config ADD COLUMN footer_hotline_label VARCHAR(255) NULL',
        'footer_hotline_number' => 'ALTER TABLE site_config ADD COLUMN footer_hotline_number VARCHAR(50) NULL',
        'footer_rights' => 'ALTER TABLE site_config ADD COLUMN footer_rights VARCHAR(255) NULL',
        'google_oauth_enabled' => 'ALTER TABLE site_config ADD COLUMN google_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'google_oauth_client_id' => 'ALTER TABLE site_config ADD COLUMN google_oauth_client_id VARCHAR(255) NULL',
        'google_oauth_client_secret' => 'ALTER TABLE site_config ADD COLUMN google_oauth_client_secret VARCHAR(255) NULL',
        'microsoft_oauth_enabled' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'microsoft_oauth_client_id' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_id VARCHAR(255) NULL',
        'microsoft_oauth_client_secret' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_secret VARCHAR(255) NULL',
        'microsoft_oauth_tenant' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_tenant VARCHAR(255) NULL',
        'color_theme' => "ALTER TABLE site_config ADD COLUMN color_theme VARCHAR(50) NOT NULL DEFAULT 'light'"
    ];

    foreach ($schema as $field => $sql) {
        if (!isset($existing[$field])) {
            $pdo->exec($sql);
        }
    }
}

/** get_site_config(): fetch branding and contact settings (singleton row id=1) */
function get_site_config(PDO $pdo): array {
    $defaults = [
        'id' => 1,
        'site_name' => 'My Performance',
        'landing_text' => null,
        'address' => null,
        'contact' => null,
        'logo_path' => null,
        'footer_org_name' => 'Ethiopian Pharmaceutical Supply Service',
        'footer_org_short' => 'EPSS / EPS',
        'footer_website_label' => 'epss.gov.et',
        'footer_website_url' => 'https://epss.gov.et',
        'footer_email' => 'info@epss.gov.et',
        'footer_phone' => '+251 11 155 9900',
        'footer_hotline_label' => 'Hotline 939',
        'footer_hotline_number' => '939',
        'footer_rights' => 'All rights reserved.',
        'google_oauth_enabled' => 0,
        'google_oauth_client_id' => null,
        'google_oauth_client_secret' => null,
        'microsoft_oauth_enabled' => 0,
        'microsoft_oauth_client_id' => null,
        'microsoft_oauth_client_secret' => null,
        'microsoft_oauth_tenant' => 'common',
        'color_theme' => 'light',
    ];

    try {
        ensure_site_config_schema($pdo);
        $pdo->exec("INSERT IGNORE INTO site_config (id, site_name, landing_text, address, contact, logo_path, footer_org_name, footer_org_short, footer_website_label, footer_website_url, footer_email, footer_phone, footer_hotline_label, footer_hotline_number, footer_rights, google_oauth_enabled, google_oauth_client_id, google_oauth_client_secret, microsoft_oauth_enabled, microsoft_oauth_client_id, microsoft_oauth_client_secret, microsoft_oauth_tenant, color_theme) VALUES (1, 'My Performance', NULL, NULL, NULL, NULL, 'Ethiopian Pharmaceutical Supply Service', 'EPSS / EPS', 'epss.gov.et', 'https://epss.gov.et', 'info@epss.gov.et', '+251 11 155 9900', 'Hotline 939', '939', 'All rights reserved.', 0, NULL, NULL, 0, NULL, NULL, 'common', 'light')");
        $cfg = $pdo->query('SELECT * FROM site_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('get_site_config failed: ' . $e->getMessage());
        return $defaults;
    }

    return array_merge($defaults, $cfg ?: []);
}

function site_color_theme(array $cfg): string
{
    $theme = strtolower((string)($cfg['color_theme'] ?? 'light'));
    $allowed = ['light', 'dark'];
    if (!in_array($theme, $allowed, true)) {
        $theme = 'light';
    }
    return $theme;
}

function site_body_classes(array $cfg): string
{
    $classes = ['md-bg'];
    $theme = site_color_theme($cfg);
    $classes[] = 'theme-' . $theme;
    return implode(' ', array_unique(array_filter($classes)));
}
?>
