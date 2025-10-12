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

    if (!defined('JSON_THROW_ON_ERROR')) {
        define('JSON_THROW_ON_ERROR', 0);
    }

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

    $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
    $dbName = getenv('DB_NAME') ?: 'epss_v300';
    $dbUser = getenv('DB_USER') ?: 'epss_user';
    $dbPass = getenv('DB_PASS') ?: 'StrongPassword123!';
    $dbPortRaw = getenv('DB_PORT');
    $dbPort = null;
    if ($dbPortRaw !== false && $dbPortRaw !== '') {
        $portCandidate = (int)$dbPortRaw;
        if ($portCandidate > 0 && $portCandidate <= 65535) {
            $dbPort = $portCandidate;
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;%sdbname=%s;charset=utf8mb4',
        $dbHost,
        $dbPort !== null ? 'port=' . $dbPort . ';' : '',
        $dbName
    );
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        ensure_site_config_schema($pdo);
        ensure_users_schema($pdo);
        ensure_user_roles_schema($pdo);
        ensure_questionnaire_item_schema($pdo);
        ensure_questionnaire_work_function_schema($pdo);
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

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
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

const DEFAULT_USER_ROLES = [
    [
        'role_key' => 'admin',
        'label' => 'Administrator',
        'description' => 'Full administrative access to manage the platform.',
        'sort_order' => 0,
        'is_protected' => 1,
    ],
    [
        'role_key' => 'supervisor',
        'label' => 'Supervisor',
        'description' => 'Can review assessments and manage assigned staff.',
        'sort_order' => 10,
        'is_protected' => 1,
    ],
    [
        'role_key' => 'staff',
        'label' => 'Staff',
        'description' => 'Standard access for employees completing assessments.',
        'sort_order' => 20,
        'is_protected' => 1,
    ],
];

const DEFAULT_BRAND_COLOR = '#2073bf';


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
    if (!isset($_SESSION['user'])) { header('Location: ' . BASE_URL . 'login.php'); exit; }
    $status = $_SESSION['user']['account_status'] ?? 'active';
    if ($status === 'disabled') {
        $_SESSION['auth_error'] = 'Your account has been disabled. Please contact your administrator.';
        unset($_SESSION['user']);
        header('Location: ' . url_for('login.php'));
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
        $pdo->exec("INSERT IGNORE INTO site_config (id, site_name, landing_text, address, contact, logo_path, footer_org_name, footer_org_short, footer_website_label, footer_website_url, footer_email, footer_phone, footer_hotline_label, footer_hotline_number, footer_rights, google_oauth_enabled, google_oauth_client_id, google_oauth_client_secret, microsoft_oauth_enabled, microsoft_oauth_client_id, microsoft_oauth_client_secret, microsoft_oauth_tenant, color_theme, brand_color, smtp_enabled, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, smtp_from_email, smtp_from_name, smtp_timeout) VALUES (1, 'My Performance', NULL, NULL, NULL, NULL, 'Ethiopian Pharmaceutical Supply Service', 'EPSS / EPS', 'epss.gov.et', 'https://epss.gov.et', 'info@epss.gov.et', '+251 11 155 9900', 'Hotline 939', '939', 'All rights reserved.', 0, NULL, NULL, 0, NULL, NULL, 'common', 'light', '#2073bf', 0, NULL, 587, NULL, NULL, 'none', NULL, NULL, 20)");
        $cfg = $pdo->query('SELECT * FROM site_config WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('get_site_config failed: ' . $e->getMessage());
        return $defaults;
    }

    $merged = array_merge($defaults, $cfg ?: []);
    $merged['logo_path'] = normalize_branding_logo_path($merged['logo_path'] ?? null);

    return $merged;
}

function normalize_branding_logo_path(?string $value): ?string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $value) === 1) {
        return $value;
    }

    return '/' . ltrim($value, '/');
}

function get_branding_logo_path(?array $cfg = null): ?string
{
    if ($cfg === null) {
        global $pdo;
        $cfg = get_site_config($pdo);
    }

    return normalize_branding_logo_path($cfg['logo_path'] ?? null);
}

function persist_branding_logo_path(PDO $pdo, ?string $path): void
{
    $normalized = normalize_branding_logo_path($path);
    $stmt = $pdo->prepare('UPDATE site_config SET logo_path = ? WHERE id = 1');
    $stmt->execute([$normalized]);
}

function ensure_users_schema(PDO $pdo): void
{
    $existing = [];
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM users');
    } catch (PDOException $e) {
        error_log('ensure_users_schema: ' . $e->getMessage());
        return;
    }
    $roleColumn = null;
    if ($columns) {
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            if (isset($col['Field'])) {
                $existing[$col['Field']] = true;
            }
            if (($col['Field'] ?? '') === 'role') {
                $roleColumn = $col;
            }
        }
    }

    if ($roleColumn && isset($roleColumn['Type']) && stripos((string)$roleColumn['Type'], 'enum(') !== false) {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'staff'");
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

function ensure_user_roles_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_role (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role_key VARCHAR(50) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_protected TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = $pdo->query('SHOW COLUMNS FROM user_role');
        $existing = [];
        if ($columns) {
            while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
                $existing[$col['Field']] = true;
            }
        }

        $required = [
            'description' => 'ALTER TABLE user_role ADD COLUMN description TEXT NULL AFTER label',
            'sort_order' => 'ALTER TABLE user_role ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER description',
            'is_protected' => 'ALTER TABLE user_role ADD COLUMN is_protected TINYINT(1) NOT NULL DEFAULT 0 AFTER sort_order',
            'created_at' => 'ALTER TABLE user_role ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_protected',
            'updated_at' => 'ALTER TABLE user_role ADD COLUMN updated_at DATETIME NULL DEFAULT NULL AFTER created_at',
        ];

        foreach ($required as $field => $sql) {
            if (!isset($existing[$field])) {
                $pdo->exec($sql);
            }
        }

        foreach (DEFAULT_USER_ROLES as $index => $role) {
            $stmt = $pdo->prepare('INSERT INTO user_role (role_key, label, description, sort_order, is_protected) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description), sort_order=VALUES(sort_order), is_protected=VALUES(is_protected)');
            $stmt->execute([
                $role['role_key'],
                $role['label'],
                $role['description'],
                $role['sort_order'] ?? ($index * 10),
                $role['is_protected'] ?? 0,
            ]);
        }
    } catch (PDOException $e) {
        error_log('ensure_user_roles_schema: ' . $e->getMessage());
    }
}

function ensure_questionnaire_item_schema(PDO $pdo): void
{
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM questionnaire_item');
        $existing = [];
        if ($columns) {
            while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
                $existing[$col['Field']] = $col;
            }
        }
        if (!isset($existing['is_required'])) {
            $pdo->exec("ALTER TABLE questionnaire_item ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_multiple");
        }
    } catch (PDOException $e) {
        error_log('ensure_questionnaire_item_schema: ' . $e->getMessage());
    }
}

function ensure_questionnaire_work_function_schema(PDO $pdo): void
{
    try {
        $enumValues = array_map(static function ($value) {
            return str_replace("'", "''", (string)$value);
        }, WORK_FUNCTIONS);
        $enumList = "'" . implode("','", $enumValues) . "'";

        $pdo->exec("CREATE TABLE IF NOT EXISTS questionnaire_work_function (
            questionnaire_id INT NOT NULL,
            work_function ENUM($enumList) NOT NULL,
            PRIMARY KEY (questionnaire_id, work_function)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $columnsStmt = $pdo->query('SHOW COLUMNS FROM questionnaire_work_function');
        $columns = [];
        if ($columnsStmt) {
            while ($column = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[$column['Field']] = $column;
            }
        }

        if (!isset($columns['work_function'])) {
            $pdo->exec("ALTER TABLE questionnaire_work_function ADD COLUMN work_function ENUM($enumList) NOT NULL AFTER questionnaire_id");
        } else {
            $pdo->exec("ALTER TABLE questionnaire_work_function MODIFY COLUMN work_function ENUM($enumList) NOT NULL");
        }

        $primaryIndex = $pdo->query("SHOW INDEX FROM questionnaire_work_function WHERE Key_name = 'PRIMARY'");
        $hasPrimary = $primaryIndex && $primaryIndex->fetch(PDO::FETCH_ASSOC);
        if (!$hasPrimary) {
            $pdo->exec('ALTER TABLE questionnaire_work_function ADD PRIMARY KEY (questionnaire_id, work_function)');
        }

        $questionnaireStmt = $pdo->query('SELECT id FROM questionnaire');
        if ($questionnaireStmt) {
            $ids = $questionnaireStmt->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) {
                $insert = $pdo->prepare('INSERT IGNORE INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
                foreach ($ids as $qid) {
                    $qid = (int)$qid;
                    foreach (WORK_FUNCTIONS as $wf) {
                        $insert->execute([$qid, $wf]);
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log('ensure_questionnaire_work_function_schema: ' . $e->getMessage());
    }
}

function load_user_roles(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = null;
    if ($forceRefresh || $cache === null) {
        try {
            $stmt = $pdo->query('SELECT id, role_key, label, description, sort_order, is_protected FROM user_role ORDER BY sort_order ASC, label ASC');
            $cache = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (PDOException $e) {
            error_log('load_user_roles: ' . $e->getMessage());
            $cache = [];
        }
    }
    return $cache ?? [];
}

function get_user_roles(PDO $pdo, bool $includeProtected = true): array
{
    $roles = load_user_roles($pdo);
    if ($includeProtected) {
        return $roles;
    }
    return array_values(array_filter($roles, static function ($role) {
        return (int)($role['is_protected'] ?? 0) === 0;
    }));
}

function get_user_role_map(PDO $pdo): array
{
    $map = [];
    foreach (load_user_roles($pdo) as $role) {
        $map[(string)$role['role_key']] = $role;
    }
    return $map;
}

function refresh_user_role_cache(PDO $pdo): void
{
    load_user_roles($pdo, true);
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
