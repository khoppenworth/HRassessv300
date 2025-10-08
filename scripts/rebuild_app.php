<?php
declare(strict_types=1);

/**
 * Rebuild the application database from scratch, including demo data and admin user.
 */

const SQL_FILES = [
    'init.sql',
    'migration.sql',
    'dummy_data.sql',
];

function env(string $key, ?string $default = null): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default ?? '';
    }
    return $value;
}

function parseFlags(array $argv): array
{
    $flags = [
        'withDummy' => true,
        'withAdmin' => true,
    ];

    foreach ($argv as $arg) {
        if ($arg === '--no-dummy') {
            $flags['withDummy'] = false;
        }
        if ($arg === '--no-admin') {
            $flags['withAdmin'] = false;
        }
    }

    return $flags;
}

function createServerPdo(string $host, string $user, string $pass, ?string $port): PDO
{
    $dsn = sprintf('mysql:host=%s;%scharset=utf8mb4', $host, $port ? 'port=' . $port . ';' : '');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function createDatabase(PDO $serverPdo, string $dbName): void
{
    $serverPdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $dbName) . '`');
    $serverPdo->exec('CREATE DATABASE `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $serverPdo->exec('USE `' . str_replace('`', '``', $dbName) . '`');
}

function sanitizeSql(string $sql): string
{
    $sql = preg_replace('~/\*.*?\*/~s', '', $sql) ?? $sql;
    $lines = [];
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*(--|#)/', $line)) {
            continue;
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function splitStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inString = false;
    $stringChar = '';
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($inString) {
            if ($char === $stringChar) {
                $backslashes = 0;
                $j = $i - 1;
                while ($j >= 0 && $sql[$j] === '\\') {
                    $backslashes++;
                    $j--;
                }
                if ($backslashes % 2 === 0) {
                    $inString = false;
                    $stringChar = '';
                }
            }
            $buffer .= $char;
            continue;
        }

        if ($char === '\'' || $char === '"') {
            $inString = true;
            $stringChar = $char;
            $buffer .= $char;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

function applySqlFile(PDO $pdo, string $filePath): int
{
    if (!file_exists($filePath)) {
        return 0;
    }
    $sql = file_get_contents($filePath);
    if ($sql === false) {
        throw new RuntimeException('Unable to read SQL file: ' . $filePath);
    }
    $sanitized = sanitizeSql($sql);
    $statements = splitStatements($sanitized);
    $count = 0;
    foreach ($statements as $statement) {
        $pdo->exec($statement);
        $count++;
    }
    return $count;
}

function connectToDatabase(string $host, string $dbName, string $user, string $pass, ?string $port): PDO
{
    $dsn = sprintf('mysql:host=%s;%sdbname=%s;charset=utf8mb4', $host, $port ? 'port=' . $port . ';' : '', $dbName);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $user, $pass, $options);
}

function seedAdmin(PDO $pdo): string
{
    $password = bin2hex(random_bytes(8));
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $username = 'admin';
    $fullName = 'System Admin';
    $email = 'admin@example.com';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $update = $pdo->prepare('UPDATE users SET password = ?, role = "admin", full_name = ?, email = ?, profile_completed = 1 WHERE id = ?');
            $update->execute([$hash, $fullName, $email, $existing]);
            $userId = (int) $existing;
        } else {
            $insert = $pdo->prepare('INSERT INTO users (username, password, role, full_name, email, profile_completed) VALUES (?,?,?,?,?,1)');
            $insert->execute([$username, $hash, 'admin', $fullName, $email]);
            $userId = (int) $pdo->lastInsertId();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return sprintf("Seeded admin user (ID: %d) with username '%s' and password '%s'", $userId, $username, $password);
}

function main(array $argv): void
{
    $flags = parseFlags(array_slice($argv, 1));

    $dbHost = env('DB_HOST', '127.0.0.1');
    $dbName = env('DB_NAME', 'epss_v300');
    $dbUser = env('DB_USER', 'epss_user');
    $dbPass = env('DB_PASS', 'StrongPassword123!');
    $dbPort = env('DB_PORT', null);

    fwrite(STDOUT, "Rebuilding database '{$dbName}' on host '{$dbHost}'.\n");

    try {
        $serverPdo = createServerPdo($dbHost, $dbUser, $dbPass, $dbPort ?: null);
        createDatabase($serverPdo, $dbName);
        fwrite(STDOUT, " - Database dropped and recreated.\n");

        $dbPdo = connectToDatabase($dbHost, $dbName, $dbUser, $dbPass, $dbPort ?: null);

        $projectRoot = dirname(__DIR__);
        foreach (SQL_FILES as $file) {
            if ($file === 'dummy_data.sql' && !$flags['withDummy']) {
                continue;
            }
            $path = $projectRoot . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($path)) {
                continue;
            }
            $count = applySqlFile($dbPdo, $path);
            fwrite(STDOUT, sprintf(" - Applied %s (%d statements).\n", $file, $count));
        }

        if ($flags['withAdmin']) {
            $message = seedAdmin($dbPdo);
            fwrite(STDOUT, ' - ' . $message . "\n");
        }

        fwrite(STDOUT, "Rebuild complete.\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'Rebuild failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    echo 'This script must be run from the command line.';
    exit;
}

main($argv);
