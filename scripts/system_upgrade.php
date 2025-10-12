#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * System upgrade script for HRassess v300.
 *
 * This script can upgrade or downgrade the application by fetching
 * a release/branch/tag from a GitHub repository. Before any upgrade
 * runs the current application directory and database are backed up.
 *
 * Usage examples:
 *  php scripts/system_upgrade.php --action=upgrade --repo=https://github.com/example/HRassessv300.git --ref=v3.1.0
 *  php scripts/system_upgrade.php --action=upgrade --repo=https://github.com/example/HRassessv300.git --latest-release
 *  php scripts/system_upgrade.php --action=downgrade --backup-id=20240211_101112 --restore-db
 *  php scripts/system_upgrade.php --action=list-backups
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be executed from the command line." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../config.php';

const BACKUP_MANIFEST_PREFIX = 'upgrade-';

/** @var array<string, mixed> $options */
$options = getopt(
    '',
    [
        'action:',
        'repo::',
        'ref::',
        'latest-release',
        'backup-dir::',
        'preserve::',
        'backup-id::',
        'restore-db',
    ]
);

$action = isset($options['action']) ? strtolower((string)$options['action']) : '';

$appPath = realpath(__DIR__ . '/..');
if ($appPath === false) {
    throw new RuntimeException('Unable to resolve application path.');
}

$backupDir = isset($options['backup-dir'])
    ? rtrim((string)$options['backup-dir'], DIRECTORY_SEPARATOR)
    : $appPath . DIRECTORY_SEPARATOR . 'backups';

ensureDirectory($backupDir);

$preserve = array_filter(array_map('trim', explode(',', (string)($options['preserve'] ?? ''))));
$defaultPreserve = [
    'config.php',
    'backups',
    'assets/backups',
    'assets/uploads',
    'storage',
];
$preservePaths = array_values(array_unique(array_merge($defaultPreserve, $preserve)));

switch ($action) {
    case 'upgrade':
        handleUpgrade($options, $appPath, $backupDir, $preservePaths);
        break;
    case 'downgrade':
        handleDowngrade($options, $appPath, $backupDir, $preservePaths);
        break;
    case 'list-backups':
        handleListBackups($backupDir);
        break;
    default:
        printUsage();
        exit($action === '' ? 0 : 1);
}

/**
 * @param array<string, string> $options
 * @param string $appPath
 * @param string $backupDir
 * @param string[] $preservePaths
 */
function handleUpgrade(array $options, string $appPath, string $backupDir, array $preservePaths): void
{
    $repo = (string)($options['repo'] ?? '');
    $latestRelease = isset($options['latest-release']);
    $ref = (string)($options['ref'] ?? '');

    if ($repo === '' && !$latestRelease && $ref === '') {
        fwrite(STDERR, "Missing --repo option. Provide the repository that should be used for upgrades." . PHP_EOL);
        exit(1);
    }

    if ($repo === '' && $latestRelease) {
        fwrite(STDERR, "The --latest-release flag requires --repo to be provided." . PHP_EOL);
        exit(1);
    }

    $timestamp = date('Ymd_His');

    info('Starting upgrade process at ' . $timestamp);

    ensureCommandAvailable('git');
    ensureCommandAvailable('tar');
    ensureCommandAvailable('mysqldump');
    ensureCommandAvailable('mysql');

    $repo = $repo !== '' ? $repo : inferRepositoryFromGit($appPath);

    $dbConfig = getDatabaseConfig();

    $resolvedRef = resolveTargetReference($repo, $ref, $latestRelease);
    info(sprintf('Using repository %s at reference %s', $repo, $resolvedRef));

    info('Backing up current application files...');
    $appBackup = backupApplicationDirectory($appPath, $backupDir, $timestamp);
    info('Application backup created at ' . $appBackup);

    info('Backing up application database...');
    $dbBackup = backupDatabase($dbConfig, $backupDir, $timestamp);
    info('Database backup created at ' . $dbBackup);

    $manifest = [
        'timestamp' => $timestamp,
        'repo' => $repo,
        'ref' => $resolvedRef,
        'started_at' => date(DATE_ATOM),
        'app_backup' => $appBackup,
        'db_backup' => $dbBackup,
        'status' => 'in-progress',
    ];

    $manifestPath = writeBackupManifest($backupDir, $manifest);

    $tempDir = createTempDirectory($backupDir, 'upgrade_');

    try {
        cloneRepository($repo, $resolvedRef, $tempDir);
        applyUpgrade($tempDir, $appPath, $preservePaths);

        $manifest['status'] = 'success';
        $manifest['completed_at'] = date(DATE_ATOM);
        writeBackupManifest($backupDir, $manifest, $manifestPath);

        info('Upgrade completed successfully.');
    } catch (Throwable $e) {
        $manifest['status'] = 'failed';
        $manifest['completed_at'] = date(DATE_ATOM);
        $manifest['error'] = $e->getMessage();
        writeBackupManifest($backupDir, $manifest, $manifestPath);

        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, 'Attempting to restore the previous state from backups...' . PHP_EOL);

        try {
            restoreApplicationFromBackup($appPath, $appBackup, $preservePaths);
            restoreDatabaseFromBackup($dbConfig, $dbBackup);
            info('Restoration complete. The system has been rolled back to the previous state.');
        } catch (Throwable $restoreException) {
            fwrite(STDERR, 'Automatic restoration failed: ' . $restoreException->getMessage() . PHP_EOL);
            fwrite(STDERR, 'Please restore manually using the backups listed in ' . $backupDir . PHP_EOL);
        }

        cleanupDirectory($tempDir);
        exit(1);
    }

    cleanupDirectory($tempDir);
}

/**
 * @param array<string, string> $options
 * @param string $appPath
 * @param string $backupDir
 * @param string[] $preservePaths
 */
function handleDowngrade(array $options, string $appPath, string $backupDir, array $preservePaths): void
{
    $backupId = (string)($options['backup-id'] ?? '');
    $restoreDb = isset($options['restore-db']);

    $manifest = $backupId !== ''
        ? findBackupManifestById($backupDir, $backupId)
        : findLatestSuccessfulBackup($backupDir);

    if ($manifest === null) {
        fwrite(STDERR, 'Unable to locate backup metadata. Use --backup-id to specify a valid backup.' . PHP_EOL);
        exit(1);
    }

    info('Preparing to restore application from backup ' . $manifest['timestamp']);

    if (!isset($manifest['app_backup']) || !is_string($manifest['app_backup'])) {
        throw new RuntimeException('Backup manifest is missing the app_backup path.');
    }

    restoreApplicationFromBackup($appPath, $manifest['app_backup'], $preservePaths);
    info('Application files restored from ' . $manifest['app_backup']);

    if ($restoreDb) {
        if (!isset($manifest['db_backup']) || !is_string($manifest['db_backup'])) {
            throw new RuntimeException('Backup manifest is missing the db_backup path.');
        }
        $dbConfig = getDatabaseConfig();
        restoreDatabaseFromBackup($dbConfig, $manifest['db_backup']);
        info('Database restored from ' . $manifest['db_backup']);
    } else {
        info('Database restore skipped (use --restore-db to enable).');
    }

    info('Downgrade / restoration completed successfully.');
}

function handleListBackups(string $backupDir): void
{
    $manifests = glob($backupDir . DIRECTORY_SEPARATOR . BACKUP_MANIFEST_PREFIX . '*.json') ?: [];
    if ($manifests === []) {
        echo 'No backups found in ' . $backupDir . PHP_EOL;
        return;
    }

    echo str_pad('Backup ID', 18)
        . str_pad('Status', 12)
        . str_pad('Reference', 25)
        . str_pad('Created At', 30)
        . 'Manifest' . PHP_EOL;
    echo str_repeat('-', 100) . PHP_EOL;

    foreach ($manifests as $manifestPath) {
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            continue;
        }
        $timestamp = (string)($manifest['timestamp'] ?? '');
        $status = (string)($manifest['status'] ?? 'unknown');
        $ref = (string)($manifest['ref'] ?? '');
        $created = (string)($manifest['started_at'] ?? '');
        echo str_pad($timestamp, 18)
            . str_pad($status, 12)
            . str_pad($ref, 25)
            . str_pad($created, 30)
            . $manifestPath . PHP_EOL;
    }
}

function printUsage(): void
{
    $script = basename(__FILE__);
    echo <<<USAGE
System upgrade utility
Usage:
  php scripts/{$script} --action=upgrade --repo=<repository-url> [--ref=<branch|tag>] [--latest-release]
  php scripts/{$script} --action=downgrade [--backup-id=<timestamp>] [--restore-db]
  php scripts/{$script} --action=list-backups

Options:
  --repo             Git repository to pull releases from. Required for upgrades when no local git remote is configured.
  --ref              Branch, tag, or commit reference to deploy. Defaults to "main" when omitted.
  --latest-release   Fetch and deploy the latest GitHub release tag for the repository.
  --backup-dir       Directory where application and database backups will be stored.
  --preserve         Comma separated list of relative paths that should not be overwritten during upgrades.
  --backup-id        Backup identifier (timestamp) to restore during downgrade operations.
  --restore-db       Restore the database from the selected backup during downgrades.

USAGE;
}

/**
 * @return array{host: string, database: string, user: string, password: string}
 */
function getDatabaseConfig(): array
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $database = getenv('DB_NAME') ?: 'epss_v300';
    $user = getenv('DB_USER') ?: 'epss_user';
    $password = getenv('DB_PASS') ?: 'StrongPassword123!';

    return [
        'host' => $host,
        'database' => $database,
        'user' => $user,
        'password' => $password,
    ];
}

/**
 * @param string $repo
 * @param string $ref
 * @param bool $latestRelease
 */
function resolveTargetReference(string $repo, string $ref, bool $latestRelease): string
{
    if ($latestRelease) {
        return fetchLatestReleaseTag($repo);
    }

    return $ref !== '' ? $ref : 'main';
}

function fetchLatestReleaseTag(string $repo): string
{
    $slug = extractGitHubSlug($repo);
    if ($slug === null) {
        throw new RuntimeException('Unable to determine GitHub repository slug from ' . $repo);
    }

    $apiUrl = sprintf('https://api.github.com/repos/%s/releases/latest', $slug);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: HRassessv300-upgrader',
                'Accept: application/vnd.github+json',
            ],
            'timeout' => 20,
        ],
    ]);

    $response = @file_get_contents($apiUrl, false, $context);
    if ($response === false) {
        throw new RuntimeException('Failed to fetch latest release information from GitHub API.');
    }

    $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['tag_name'])) {
        throw new RuntimeException('GitHub API response does not include a tag_name field.');
    }

    return (string)$data['tag_name'];
}

function extractGitHubSlug(string $repo): ?string
{
    $parsed = parse_url($repo);
    if ($parsed === false || !isset($parsed['host']) || stripos($parsed['host'], 'github.com') === false) {
        return null;
    }

    $path = $parsed['path'] ?? '';
    $path = trim($path, '/');
    if ($path === '') {
        return null;
    }

    $segments = explode('/', $path);
    if (count($segments) < 2) {
        return null;
    }

    $owner = $segments[0];
    $repoName = preg_replace('/\.git$/', '', $segments[1]);
    if ($repoName === null) {
        return null;
    }

    return $owner . '/' . $repoName;
}

function inferRepositoryFromGit(string $appPath): string
{
    $output = runShellCommand('git -C ' . escapeshellarg($appPath) . ' remote get-url origin');
    $remote = trim($output['stdout']);
    if ($remote === '') {
        throw new RuntimeException('Unable to infer repository URL from git remotes. Please provide --repo.');
    }

    return $remote;
}

/**
 * @param string $repo
 * @param string $ref
 * @param string $tempDir
 */
function cloneRepository(string $repo, string $ref, string $tempDir): void
{
    $target = $tempDir . DIRECTORY_SEPARATOR . 'repository';
    info('Cloning repository to ' . $target);
    $cmd = sprintf(
        'git clone --depth 1 --branch %s %s %s',
        escapeshellarg($ref),
        escapeshellarg($repo),
        escapeshellarg($target)
    );
    runShellCommand($cmd);
}

/**
 * @param string $sourceBase
 * @param string $appPath
 * @param string[] $preservePaths
 */
function applyUpgrade(string $sourceBase, string $appPath, array $preservePaths): void
{
    $source = $sourceBase . DIRECTORY_SEPARATOR . 'repository';
    if (!is_dir($source)) {
        throw new RuntimeException('Cloned repository not found at ' . $source);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        if (shouldSkipPath($relative, $preservePaths)) {
            continue;
        }
        if ($relative === '.git' || str_starts_with($relative, '.git' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $target = $appPath . DIRECTORY_SEPARATOR . $relative;

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException('Unable to create directory ' . $target);
            }
            continue;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Unable to create directory ' . $targetDir);
        }

        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('Failed to copy file to ' . $target);
        }
    }
}

function shouldSkipPath(string $relative, array $preservePaths): bool
{
    $normalized = normalizeRelativePath($relative);
    foreach ($preservePaths as $preserve) {
        $normalizedPreserve = normalizeRelativePath($preserve);
        if ($normalizedPreserve === '') {
            continue;
        }
        if ($normalizedPreserve === $normalized) {
            return true;
        }
        if (str_starts_with($normalized, $normalizedPreserve . '/')) {
            return true;
        }
    }

    return false;
}

function normalizeRelativePath(string $path): string
{
    $path = str_replace(chr(92), '/', $path);
    $path = ltrim($path, "./");
    return trim($path, '/');
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException("Unable to create directory {$path}");
    }
}

/**
 * @param string $appPath
 * @param string $backupDir
 * @param string $timestamp
 */
function backupApplicationDirectory(string $appPath, string $backupDir, string $timestamp): string
{
    $archive = $backupDir . DIRECTORY_SEPARATOR . 'app-' . $timestamp . '.tar.gz';
    $cmd = sprintf(
        'tar -czf %s --exclude=./backups --exclude=./.git -C %s .',
        escapeshellarg($archive),
        escapeshellarg($appPath)
    );
    runShellCommand($cmd);

    return $archive;
}

/**
 * @param array{host: string, database: string, user: string, password: string} $dbConfig
 * @param string $backupDir
 * @param string $timestamp
 */
function backupDatabase(array $dbConfig, string $backupDir, string $timestamp): string
{
    $file = $backupDir . DIRECTORY_SEPARATOR . 'db-' . $timestamp . '.sql';
    $cmd = sprintf(
        'mysqldump --user=%s --password=%s --host=%s %s > %s',
        escapeshellarg($dbConfig['user']),
        escapeshellarg($dbConfig['password']),
        escapeshellarg($dbConfig['host']),
        escapeshellarg($dbConfig['database']),
        escapeshellarg($file)
    );
    runShellCommand($cmd);

    return $file;
}

/**
 * @param array{host: string, database: string, user: string, password: string} $dbConfig
 * @param string $backupFile
 */
function restoreDatabaseFromBackup(array $dbConfig, string $backupFile): void
{
    if (!is_file($backupFile)) {
        throw new RuntimeException('Database backup not found: ' . $backupFile);
    }

    $cmd = sprintf(
        'mysql --user=%s --password=%s --host=%s %s < %s',
        escapeshellarg($dbConfig['user']),
        escapeshellarg($dbConfig['password']),
        escapeshellarg($dbConfig['host']),
        escapeshellarg($dbConfig['database']),
        escapeshellarg($backupFile)
    );
    runShellCommand($cmd);
}

/**
 * @param string $appPath
 * @param string $archivePath
 * @param string[] $preservePaths
 */
function restoreApplicationFromBackup(string $appPath, string $archivePath, array $preservePaths): void
{
    if (!is_file($archivePath)) {
        throw new RuntimeException('Application backup archive not found: ' . $archivePath);
    }

    cleanApplicationDirectory($appPath, $preservePaths);

    $cmd = sprintf(
        'tar -xzf %s -C %s',
        escapeshellarg($archivePath),
        escapeshellarg($appPath)
    );
    runShellCommand($cmd);
}

function cleanApplicationDirectory(string $appPath, array $preservePaths): void
{
    $items = scandir($appPath);
    if ($items === false) {
        throw new RuntimeException('Unable to list directory: ' . $appPath);
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (shouldSkipPath($item, $preservePaths)) {
            continue;
        }

        $fullPath = $appPath . DIRECTORY_SEPARATOR . $item;
        deletePath($fullPath);
    }
}

function deletePath(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException('Unable to list directory: ' . $path);
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            deletePath($path . DIRECTORY_SEPARATOR . $item);
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove directory: ' . $path);
        }
        return;
    }

    if (file_exists($path) && !unlink($path)) {
        throw new RuntimeException('Unable to remove file: ' . $path);
    }
}

function ensureCommandAvailable(string $command): void
{
    $result = runShellCommand('command -v ' . escapeshellarg($command));
    if (trim($result['stdout']) === '') {
        throw new RuntimeException(sprintf('Required command "%s" is not available in PATH.', $command));
    }
}

/**
 * @return array{stdout: string, stderr: string}
 */
function runShellCommand(string $command): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(['/bin/sh', '-c', $command], $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to execute command: ' . $command);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $status = proc_close($process);
    if ($status !== 0) {
        $maskedCommand = maskSensitiveCommand($command);
        throw new RuntimeException(
            sprintf(
                'Command failed with exit code %d: %s%s',
                $status,
                $maskedCommand,
                $stderr !== '' ? PHP_EOL . trim((string)$stderr) : ''
            )
        );
    }

    return [
        'stdout' => (string)$stdout,
        'stderr' => (string)$stderr,
    ];
}

function info(string $message): void
{
    echo '[INFO] ' . $message . PHP_EOL;
}

function maskSensitiveCommand(string $command): string
{
    return preg_replace('/(--password=)([^\s]+)/', '$1******', $command) ?? $command;
}

/**
 * @param array<string, mixed> $manifest
 * @return string Path to manifest
 */
function writeBackupManifest(string $backupDir, array $manifest, ?string $existingPath = null): string
{
    $path = $existingPath ?? ($backupDir . DIRECTORY_SEPARATOR . BACKUP_MANIFEST_PREFIX . $manifest['timestamp'] . '.json');
    file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $path;
}

/**
 * @return array<string, mixed>|null
 */
function findBackupManifestById(string $backupDir, string $backupId): ?array
{
    $path = $backupDir . DIRECTORY_SEPARATOR . BACKUP_MANIFEST_PREFIX . $backupId . '.json';
    if (!is_file($path)) {
        return null;
    }

    $manifest = json_decode((string)file_get_contents($path), true);
    return is_array($manifest) ? $manifest : null;
}

/**
 * @return array<string, mixed>|null
 */
function findLatestSuccessfulBackup(string $backupDir): ?array
{
    $manifests = glob($backupDir . DIRECTORY_SEPARATOR . BACKUP_MANIFEST_PREFIX . '*.json') ?: [];
    rsort($manifests);
    foreach ($manifests as $manifestPath) {
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            continue;
        }
        if (($manifest['status'] ?? null) === 'success') {
            return $manifest;
        }
    }

    return null;
}

function createTempDirectory(string $baseDir, string $prefix = 'tmp_'): string
{
    $tempDir = $baseDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
    if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
        throw new RuntimeException('Unable to create temporary directory: ' . $tempDir);
    }

    return $tempDir;
}

function cleanupDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isDir() && !is_link($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

