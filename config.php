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
    require_once __DIR__ . '/lib/mailer.php';
    require_once __DIR__ . '/lib/notifications.php';

    $locale = ensure_locale();
    if (!isset($_SESSION['lang']) || $_SESSION['lang'] !== $locale) {
        $_SESSION['lang'] = $locale;
    }

    apply_security_headers($appDebug);

    if (!defined('WORK_FUNCTIONS')) {
        define('WORK_FUNCTIONS', [
            'finance','general_service','hrm','ict','leadership_tn','legal_service','pme','quantification',
            'records_documentation','security_driver','security','tmd','wim','cmd','communication','dfm','driver','ethics'
        ]);
    }
    if (!defined('WORK_FUNCTION_LABELS')) {
        define('WORK_FUNCTION_LABELS', [
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
        ]);
    }
    if (!defined('DEFAULT_BRAND_COLOR')) {
        define('DEFAULT_BRAND_COLOR', '#2073bf');
    }

    $dbDriver = strtolower((string)(getenv('DB_DRIVER') ?: 'sqlite'));
    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'epss_v300';
    $dbUser = getenv('DB_USER') ?: 'epss_user';
    $dbPass = getenv('DB_PASS') ?: 'StrongPassword123!';

    if ($dbDriver === 'sqlite') {
        $dbPath = getenv('DB_PATH') ?: (BASE_PATH . '/storage/database.sqlite');
        $dbPath = str_replace('\\', '/', $dbPath);
        $dbDir = dirname($dbPath);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0775, true);
        }
        $dsn = 'sqlite:' . $dbPath;
        $dbUser = null;
        $dbPass = null;
    } else {
        $dbDriver = 'mysql';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbName);
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if ($dbDriver === 'mysql') {
        $options[PDO::ATTR_EMULATE_PREPARES] = false;
    }

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        define('DB_DRIVER', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        ensure_site_config_schema($pdo);
        ensure_users_schema($pdo);
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

function pdo_driver(PDO $pdo): string
{
    if (defined('DB_DRIVER')) {
        return DB_DRIVER;
    }
    return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

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
    $status = $_SESSION['user']['account_status'] ?? 'active';
    if ($status === 'disabled') {
        $_SESSION['auth_error'] = 'Your account has been disabled. Please contact your administrator.';
        unset($_SESSION['user']);
        header('Location: ' . url_for('index.php'));
        exit;
    }
    if ($status === 'pending') {
        $allowedScripts = ['profile.php', 'logout.php', 'set_lang.php'];
        $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (!in_array($current, $allowedScripts, true)) {
            $_SESSION['pending_notice'] = true;
            header('Location: ' . url_for('profile.php?pending=1'));
            exit;
        }
    }
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
    $driver = pdo_driver($pdo);

    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS site_config (
            id INTEGER PRIMARY KEY,
            site_name TEXT NULL,
            landing_text TEXT NULL,
            address TEXT NULL,
            contact TEXT NULL,
            logo_path TEXT NULL,
            footer_org_name TEXT NULL,
            footer_org_short TEXT NULL,
            footer_website_label TEXT NULL,
            footer_website_url TEXT NULL,
            footer_email TEXT NULL,
            footer_phone TEXT NULL,
            footer_hotline_label TEXT NULL,
            footer_hotline_number TEXT NULL,
            footer_rights TEXT NULL,
            google_oauth_enabled INTEGER NOT NULL DEFAULT 0,
            google_oauth_client_id TEXT NULL,
            google_oauth_client_secret TEXT NULL,
            microsoft_oauth_enabled INTEGER NOT NULL DEFAULT 0,
            microsoft_oauth_client_id TEXT NULL,
            microsoft_oauth_client_secret TEXT NULL,
            microsoft_oauth_tenant TEXT NULL,
            color_theme TEXT NOT NULL DEFAULT 'light',
            brand_color TEXT NULL,
            smtp_enabled INTEGER NOT NULL DEFAULT 0,
            smtp_host TEXT NULL,
            smtp_port INTEGER NULL,
            smtp_username TEXT NULL,
            smtp_password TEXT NULL,
            smtp_encryption TEXT NOT NULL DEFAULT 'none',
            smtp_from_email TEXT NULL,
            smtp_from_name TEXT NULL,
            smtp_timeout INTEGER NULL
        )");
        $columns = $pdo->query('PRAGMA table_info(site_config)');
        $existing = [];
        if ($columns) {
            while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
                if (isset($col['name'])) {
                    $existing[$col['name']] = true;
                }
            }
        }
        $schema = [
            'site_name' => 'ALTER TABLE site_config ADD COLUMN site_name TEXT NULL',
            'landing_text' => 'ALTER TABLE site_config ADD COLUMN landing_text TEXT NULL',
            'address' => 'ALTER TABLE site_config ADD COLUMN address TEXT NULL',
            'contact' => 'ALTER TABLE site_config ADD COLUMN contact TEXT NULL',
            'logo_path' => 'ALTER TABLE site_config ADD COLUMN logo_path TEXT NULL',
            'footer_org_name' => 'ALTER TABLE site_config ADD COLUMN footer_org_name TEXT NULL',
            'footer_org_short' => 'ALTER TABLE site_config ADD COLUMN footer_org_short TEXT NULL',
            'footer_website_label' => 'ALTER TABLE site_config ADD COLUMN footer_website_label TEXT NULL',
            'footer_website_url' => 'ALTER TABLE site_config ADD COLUMN footer_website_url TEXT NULL',
            'footer_email' => 'ALTER TABLE site_config ADD COLUMN footer_email TEXT NULL',
            'footer_phone' => 'ALTER TABLE site_config ADD COLUMN footer_phone TEXT NULL',
            'footer_hotline_label' => 'ALTER TABLE site_config ADD COLUMN footer_hotline_label TEXT NULL',
            'footer_hotline_number' => 'ALTER TABLE site_config ADD COLUMN footer_hotline_number TEXT NULL',
            'footer_rights' => 'ALTER TABLE site_config ADD COLUMN footer_rights TEXT NULL',
            'google_oauth_enabled' => 'ALTER TABLE site_config ADD COLUMN google_oauth_enabled INTEGER NOT NULL DEFAULT 0',
            'google_oauth_client_id' => 'ALTER TABLE site_config ADD COLUMN google_oauth_client_id TEXT NULL',
            'google_oauth_client_secret' => 'ALTER TABLE site_config ADD COLUMN google_oauth_client_secret TEXT NULL',
            'microsoft_oauth_enabled' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_enabled INTEGER NOT NULL DEFAULT 0',
            'microsoft_oauth_client_id' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_id TEXT NULL',
            'microsoft_oauth_client_secret' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_client_secret TEXT NULL',
            'microsoft_oauth_tenant' => 'ALTER TABLE site_config ADD COLUMN microsoft_oauth_tenant TEXT NULL',
            'color_theme' => "ALTER TABLE site_config ADD COLUMN color_theme TEXT NOT NULL DEFAULT 'light'",
            'brand_color' => 'ALTER TABLE site_config ADD COLUMN brand_color TEXT NULL',
            'smtp_enabled' => 'ALTER TABLE site_config ADD COLUMN smtp_enabled INTEGER NOT NULL DEFAULT 0',
            'smtp_host' => 'ALTER TABLE site_config ADD COLUMN smtp_host TEXT NULL',
            'smtp_port' => 'ALTER TABLE site_config ADD COLUMN smtp_port INTEGER NULL',
            'smtp_username' => 'ALTER TABLE site_config ADD COLUMN smtp_username TEXT NULL',
            'smtp_password' => 'ALTER TABLE site_config ADD COLUMN smtp_password TEXT NULL',
            'smtp_encryption' => "ALTER TABLE site_config ADD COLUMN smtp_encryption TEXT NOT NULL DEFAULT 'none'",
            'smtp_from_email' => 'ALTER TABLE site_config ADD COLUMN smtp_from_email TEXT NULL',
            'smtp_from_name' => 'ALTER TABLE site_config ADD COLUMN smtp_from_name TEXT NULL',
            'smtp_timeout' => 'ALTER TABLE site_config ADD COLUMN smtp_timeout INTEGER NULL',
        ];
    } else {
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
        $columns = $pdo->query('SHOW COLUMNS FROM site_config');
        $existing = [];
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
            'color_theme' => "ALTER TABLE site_config ADD COLUMN color_theme VARCHAR(50) NOT NULL DEFAULT 'light'",
            'brand_color' => "ALTER TABLE site_config ADD COLUMN brand_color VARCHAR(7) NULL AFTER color_theme",
            'smtp_enabled' => 'ALTER TABLE site_config ADD COLUMN smtp_enabled TINYINT(1) NOT NULL DEFAULT 0',
            'smtp_host' => 'ALTER TABLE site_config ADD COLUMN smtp_host VARCHAR(255) NULL',
            'smtp_port' => 'ALTER TABLE site_config ADD COLUMN smtp_port INT NULL',
            'smtp_username' => 'ALTER TABLE site_config ADD COLUMN smtp_username VARCHAR(255) NULL',
            'smtp_password' => 'ALTER TABLE site_config ADD COLUMN smtp_password VARCHAR(255) NULL',
            'smtp_encryption' => "ALTER TABLE site_config ADD COLUMN smtp_encryption VARCHAR(10) NOT NULL DEFAULT 'none'",
            'smtp_from_email' => 'ALTER TABLE site_config ADD COLUMN smtp_from_email VARCHAR(255) NULL',
            'smtp_from_name' => 'ALTER TABLE site_config ADD COLUMN smtp_from_name VARCHAR(255) NULL',
            'smtp_timeout' => 'ALTER TABLE site_config ADD COLUMN smtp_timeout INT NULL'
        ];
    }

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
        'brand_color' => '#2073bf',
        'smtp_enabled' => 0,
        'smtp_host' => null,
        'smtp_port' => 587,
        'smtp_username' => null,
        'smtp_password' => null,
        'smtp_encryption' => 'none',
        'smtp_from_email' => null,
        'smtp_from_name' => null,
        'smtp_timeout' => 20,
    ];

    try {
        ensure_site_config_schema($pdo);
        $driver = pdo_driver($pdo);
        if ($driver === 'sqlite') {
            $pdo->exec("INSERT INTO site_config (id, site_name, landing_text, address, contact, logo_path, footer_org_name, footer_org_short, footer_website_label, footer_website_url, footer_email, footer_phone, footer_hotline_label, footer_hotline_number, footer_rights, google_oauth_enabled, google_oauth_client_id, google_oauth_client_secret, microsoft_oauth_enabled, microsoft_oauth_client_id, microsoft_oauth_client_secret, microsoft_oauth_tenant, color_theme, brand_color, smtp_enabled, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, smtp_from_email, smtp_from_name, smtp_timeout) VALUES (1, 'My Performance', NULL, NULL, NULL, NULL, 'Ethiopian Pharmaceutical Supply Service', 'EPSS / EPS', 'epss.gov.et', 'https://epss.gov.et', 'info@epss.gov.et', '+251 11 155 9900', 'Hotline 939', '939', 'All rights reserved.', 0, NULL, NULL, 0, NULL, NULL, 'common', 'light', '#2073bf', 0, NULL, 587, NULL, NULL, 'none', NULL, NULL, 20) ON CONFLICT(id) DO NOTHING");
        } else {
            $pdo->exec("INSERT IGNORE INTO site_config (id, site_name, landing_text, address, contact, logo_path, footer_org_name, footer_org_short, footer_website_label, footer_website_url, footer_email, footer_phone, footer_hotline_label, footer_hotline_number, footer_rights, google_oauth_enabled, google_oauth_client_id, google_oauth_client_secret, microsoft_oauth_enabled, microsoft_oauth_client_id, microsoft_oauth_client_secret, microsoft_oauth_tenant, color_theme, brand_color, smtp_enabled, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, smtp_from_email, smtp_from_name, smtp_timeout) VALUES (1, 'My Performance', NULL, NULL, NULL, NULL, 'Ethiopian Pharmaceutical Supply Service', 'EPSS / EPS', 'epss.gov.et', 'https://epss.gov.et', 'info@epss.gov.et', '+251 11 155 9900', 'Hotline 939', '939', 'All rights reserved.', 0, NULL, NULL, 0, NULL, NULL, 'common', 'light', '#2073bf', 0, NULL, 587, NULL, NULL, 'none', NULL, NULL, 20)");
        }
        $cfg = $pdo->query('SELECT * FROM site_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('get_site_config failed: ' . $e->getMessage());
        return $defaults;
    }

    return array_merge($defaults, $cfg ?: []);
}

function ensure_users_schema(PDO $pdo): void
{
    $driver = pdo_driver($pdo);

    if ($driver === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'staff',
            full_name TEXT NULL,
            email TEXT NULL,
            gender TEXT NULL,
            date_of_birth TEXT NULL,
            phone TEXT NULL,
            department TEXT NULL,
            cadre TEXT NULL,
            work_function TEXT NOT NULL DEFAULT 'general_service',
            profile_completed INTEGER NOT NULL DEFAULT 0,
            language TEXT NOT NULL DEFAULT 'en',
            account_status TEXT NOT NULL DEFAULT 'active',
            next_assessment_date TEXT NULL,
            first_login_at TEXT NULL,
            approved_by INTEGER NULL,
            approved_at TEXT NULL,
            sso_provider TEXT NULL
        )");
        $columns = $pdo->query('PRAGMA table_info(users)');
        $existing = [];
        if ($columns) {
            while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
                if (isset($col['name'])) {
                    $existing[$col['name']] = true;
                }
            }
        }
        $changes = [
            'profile_completed' => 'ALTER TABLE users ADD COLUMN profile_completed INTEGER NOT NULL DEFAULT 0',
            'language' => "ALTER TABLE users ADD COLUMN language TEXT NOT NULL DEFAULT 'en'",
            'account_status' => "ALTER TABLE users ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active'",
            'next_assessment_date' => 'ALTER TABLE users ADD COLUMN next_assessment_date TEXT NULL',
            'approved_by' => 'ALTER TABLE users ADD COLUMN approved_by INTEGER NULL',
            'approved_at' => 'ALTER TABLE users ADD COLUMN approved_at TEXT NULL',
            'sso_provider' => 'ALTER TABLE users ADD COLUMN sso_provider TEXT NULL',
        ];
        foreach ($changes as $field => $sql) {
            if (!isset($existing[$field])) {
                $pdo->exec($sql);
            }
        }
        return;
    }

    $existing = [];
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM users');
    } catch (PDOException $e) {
        error_log('ensure_users_schema: ' . $e->getMessage());
        return;
    }
    if ($columns) {
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            if (isset($col['Field'])) {
                $existing[$col['Field']] = true;
            }
        }
    }

    $changes = [
        'account_status' => "ALTER TABLE users ADD COLUMN account_status ENUM('pending','active','disabled') NOT NULL DEFAULT 'active' AFTER language",
        'next_assessment_date' => 'ALTER TABLE users ADD COLUMN next_assessment_date DATE NULL AFTER account_status',
        'approved_by' => 'ALTER TABLE users ADD COLUMN approved_by INT NULL AFTER next_assessment_date',
        'approved_at' => 'ALTER TABLE users ADD COLUMN approved_at DATETIME NULL AFTER approved_by',
        'sso_provider' => 'ALTER TABLE users ADD COLUMN sso_provider VARCHAR(50) NULL AFTER approved_at',
    ];

    foreach ($changes as $field => $sql) {
        if (!isset($existing[$field])) {
            $pdo->exec($sql);
        }
    }
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

function site_brand_color(array $cfg): string
{
    $raw = strtolower(trim((string)($cfg['brand_color'] ?? '')));
    if ($raw !== '' && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $raw)) {
        if (strlen($raw) === 4) {
            $raw = '#' . $raw[1] . $raw[1] . $raw[2] . $raw[2] . $raw[3] . $raw[3];
        }
        return $raw;
    }
    return DEFAULT_BRAND_COLOR;
}

function site_brand_palette(array $cfg): array
{
    $base = site_brand_color($cfg);
    $rgb = hex_to_rgb($base);
    $primaryDark = mix_colors($base, '#000000', 0.32);
    $primaryDarker = mix_colors($base, '#000000', 0.5);
    $primaryLight = mix_colors($base, '#ffffff', 0.32);
    $secondary = mix_colors($base, '#ffffff', 0.45);
    $muted = mix_colors($base, '#000000', 0.6);
    $mutedLight = mix_colors($base, '#ffffff', 0.55);
    $bgStart = mix_colors($primaryLight, '#ffffff', 0.35);
    $bgMid = mix_colors($secondary, '#ffffff', 0.2);
    $bgEnd = mix_colors($base, '#ffffff', 0.55);

    return [
        'primary' => $base,
        'primaryDark' => $primaryDark,
        'primaryDarker' => $primaryDarker,
        'primaryLight' => $primaryLight,
        'secondary' => $secondary,
        'muted' => $muted,
        'mutedLight' => $mutedLight,
        'rgb' => $rgb,
        'bgStart' => $bgStart,
        'bgMid' => $bgMid,
        'bgEnd' => $bgEnd,
    ];
}

function site_brand_style(array $cfg): string
{
    $palette = site_brand_palette($cfg);
    $rgb = $palette['rgb'];
    $border = sprintf('rgba(%d, %d, %d, 0.12)', $rgb[0], $rgb[1], $rgb[2]);
    $shadow = sprintf('0 18px 44px rgba(%d, %d, %d, 0.18)', $rgb[0], $rgb[1], $rgb[2]);
    $values = [
        '--brand-primary: ' . $palette['primary'],
        '--brand-primary-dark: ' . $palette['primaryDark'],
        '--brand-primary-darker: ' . $palette['primaryDarker'],
        '--brand-primary-light: ' . $palette['primaryLight'],
        '--brand-secondary: ' . $palette['secondary'],
        '--brand-muted: ' . $palette['muted'],
        '--brand-muted-light: ' . $palette['mutedLight'],
        '--brand-border: ' . $border,
        '--brand-shadow: ' . $shadow,
        '--brand-bg: linear-gradient(140deg, ' . $palette['bgStart'] . ' 0%, ' . $palette['bgMid'] . ' 45%, ' . $palette['bgEnd'] . ' 100%)',
    ];
    return implode('; ', $values);
}

function site_body_style(array $cfg): string
{
    return site_brand_style($cfg);
}

function hex_to_rgb(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $int = hexdec($hex);
    return [($int >> 16) & 0xff, ($int >> 8) & 0xff, $int & 0xff];
}

function mix_colors(string $hex, string $target, float $ratio): string
{
    $ratio = max(0.0, min(1.0, $ratio));
    [$r1, $g1, $b1] = hex_to_rgb($hex);
    [$r2, $g2, $b2] = hex_to_rgb($target);
    $mix = static function ($a, $b) use ($ratio) {
        return (int)round($a * (1 - $ratio) + $b * $ratio);
    };
    return sprintf('#%02x%02x%02x', $mix($r1, $r2), $mix($g1, $g2), $mix($b1, $b2));
}
?>
