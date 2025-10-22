#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script can only be executed from the command line." . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/upgrade.php';

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
    ensureZipArchiveAvailable();

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

    $repo = $repo !== '' ? $repo : inferRepositoryFromGit($appPath);

    $dbConfig = getDatabaseConfig();
    $target = resolveTargetReference($repo, $ref, $latestRelease);
    $resolvedRef = $target['ref'];
    $resolvedLabel = $target['label'];
    $resolvedUrl = $target['url'];
    info(sprintf('Using repository %s at reference %s', $repo, $resolvedRef));

    $manifest = [
        'id' => $timestamp,
        'timestamp' => $timestamp,
        'repo' => $repo,
        'ref' => $resolvedRef,
        'version_label' => $resolvedLabel,
        'release_url' => $resolvedUrl,
        'status' => 'pending',
        'started_at' => date(DATE_ATOM),
        'preserve' => $preservePaths,
    ];

    $manifestPath = writeBackupManifest($backupDir, $manifest);

    try {
        info('Creating application snapshot...');
        $snapshot = createApplicationSnapshot($appPath, $backupDir, $timestamp, $preservePaths);
        $manifest['app_snapshot'] = $snapshot;
        writeBackupManifest($backupDir, $manifest, $manifestPath);
        info('Snapshot stored at ' . $snapshot);

        info('Backing up application database...');
        $cliAvailable = commandAvailable('mysqldump') && commandAvailable('mysql');
        if (!$cliAvailable) {
            info('mysqldump/mysql not available; using built-in backup routine.');
        }
        $dbBackup = $cliAvailable
            ? backupDatabaseCli($dbConfig, $backupDir, $timestamp)
            : backupDatabaseInline($dbConfig, $backupDir, $timestamp);
        $manifest['db_backup'] = $dbBackup;
        $manifest['db_backup_strategy'] = $cliAvailable ? 'cli' : 'php';
        writeBackupManifest($backupDir, $manifest, $manifestPath);
        info('Database backup created at ' . $dbBackup);

        $workDir = createTempDirectory($backupDir, 'release_');
        try {
            info('Fetching release package...');
            $package = fetchReleasePackage($repo, $resolvedRef, $workDir);
            $manifest['package'] = $package;
            writeBackupManifest($backupDir, $manifest, $manifestPath);
            info(sprintf('Release extracted to %s', $package['path']));

            info('Deploying new release...');
            installRelease($package['path'], $appPath, $preservePaths);
            info('Release deployed successfully.');
        } finally {
            cleanupDirectory($workDir);
        }

        $manifest['status'] = 'success';
        $manifest['completed_at'] = date(DATE_ATOM);
        writeBackupManifest($backupDir, $manifest, $manifestPath);
        try {
            upgrade_store_installed_release([
                'tag' => $resolvedRef,
                'name' => $resolvedLabel,
                'repo' => $repo,
                'url' => $resolvedUrl,
                'installed_at' => $manifest['completed_at'],
            ]);
        } catch (Throwable $metaError) {
            error_log('Unable to persist installed release metadata: ' . $metaError->getMessage());
        }
        info('Upgrade completed successfully.');
    } catch (Throwable $e) {
        $manifest['status'] = 'failed';
        $manifest['completed_at'] = date(DATE_ATOM);
        $manifest['error'] = $e->getMessage();
        writeBackupManifest($backupDir, $manifest, $manifestPath);

        fwrite(STDERR, 'Upgrade failed: ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, 'Attempting to restore the previous state from backups...' . PHP_EOL);

        try {
            if (isset($manifest['app_snapshot']) && is_string($manifest['app_snapshot'])) {
                restoreApplicationSnapshot($appPath, $manifest['app_snapshot'], $preservePaths);
                info('Application files restored from snapshot.');
            }
            if (isset($manifest['db_backup']) && is_string($manifest['db_backup'])) {
                $cliAvailable = commandAvailable('mysql');
                if (!$cliAvailable) {
                    info('mysql command not available; restoring database using built-in routine.');
                }
                restoreDatabaseFromBackup($dbConfig, $manifest['db_backup'], $cliAvailable);
                info('Database restored from ' . $manifest['db_backup']);
            }
        } catch (Throwable $restoreException) {
            fwrite(STDERR, 'Automatic restoration failed: ' . $restoreException->getMessage() . PHP_EOL);
            fwrite(STDERR, 'Please restore manually using the backups listed in ' . $backupDir . PHP_EOL);
        }

        exit(1);
    }
}

/**
 * @param array<string, string> $options
 * @param string $appPath
 * @param string $backupDir
 * @param string[] $preservePaths
 */
function handleDowngrade(array $options, string $appPath, string $backupDir, array $preservePaths): void
{
    ensureZipArchiveAvailable();

    $backupId = (string)($options['backup-id'] ?? '');
    $restoreDb = isset($options['restore-db']);

    $manifest = $backupId !== ''
        ? findBackupManifestById($backupDir, $backupId)
        : findLatestSuccessfulBackup($backupDir);

    if ($manifest === null) {
        fwrite(STDERR, 'Unable to locate backup metadata. Use --backup-id to specify a valid backup.' . PHP_EOL);
        exit(1);
    }

    $timestamp = (string)($manifest['timestamp'] ?? 'unknown');
    info('Preparing to restore application snapshot from upgrade ' . $timestamp);

    $snapshot = null;
    if (isset($manifest['app_snapshot']) && is_string($manifest['app_snapshot'])) {
        $snapshot = $manifest['app_snapshot'];
    } elseif (isset($manifest['app_backup']) && is_string($manifest['app_backup'])) {
        $snapshot = $manifest['app_backup'];
    }

    if ($snapshot === null) {
        throw new RuntimeException('Backup manifest does not include an application snapshot.');
    }

    restoreApplicationSnapshot($appPath, $snapshot, $preservePaths);
    info('Application files restored from ' . $snapshot);

    if ($restoreDb) {
        if (!isset($manifest['db_backup']) || !is_string($manifest['db_backup'])) {
            throw new RuntimeException('Backup manifest is missing the db_backup path.');
        }
        $dbConfig = getDatabaseConfig();
        $cliAvailable = commandAvailable('mysql');
        if (!$cliAvailable) {
            info('mysql command not available; restoring database using built-in routine.');
        }
        restoreDatabaseFromBackup($dbConfig, $manifest['db_backup'], $cliAvailable);
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
 * @return array{host: string, database: string, user: string, password: string, port: int|null}
 */
function getDatabaseConfig(): array
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $database = getenv('DB_NAME') ?: 'epss_v300';
    $user = getenv('DB_USER') ?: 'epss_user';
    $password = getenv('DB_PASS') ?: 'StrongPassword123!';
    $portRaw = getenv('DB_PORT');
    $port = null;
    if ($portRaw !== false && $portRaw !== '') {
        $candidate = (int)$portRaw;
        if ($candidate > 0 && $candidate <= 65535) {
            $port = $candidate;
        }
    }

    return [
        'host' => $host,
        'database' => $database,
        'user' => $user,
        'password' => $password,
        'port' => $port,
    ];
}

/**
 * @param string $repo
 * @param string $ref
 * @param bool $latestRelease
 * @return array{ref: string, label: string, url: ?string}
 */
function resolveTargetReference(string $repo, string $ref, bool $latestRelease): array
{
    if ($latestRelease) {
        $token = trim((string)(getenv('GITHUB_TOKEN') ?: ''));
        $release = upgrade_fetch_latest_release($repo, $token !== '' ? $token : null);
        if ($release === null) {
            throw new RuntimeException('Unable to determine latest release for repository ' . $repo);
        }

        $tag = (string)$release['tag'];
        $name = isset($release['name']) && $release['name'] !== ''
            ? (string)$release['name']
            : $tag;

        return [
            'ref' => $tag,
            'label' => $name,
            'url' => isset($release['url']) ? (string)$release['url'] : null,
        ];
    }

    $resolved = $ref !== '' ? $ref : 'main';

    return [
        'ref' => $resolved,
        'label' => $resolved,
        'url' => null,
    ];
}

function extractGitHubSlug(string $repo): ?string
{
    if (preg_match('/^[\w.-]+\/[\w.-]+$/', $repo) === 1) {
        return $repo;
    }

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
    ensureCommandAvailable('git');
    $output = runShellCommand('git -C ' . escapeshellarg($appPath) . ' remote get-url origin');
    $remote = trim($output['stdout']);
    if ($remote === '') {
        throw new RuntimeException('Unable to infer repository URL from git remotes. Please provide --repo.');
    }

    return $remote;
}

/**
 * @return array{type: string, path: string, source: string}
 */
function fetchReleasePackage(string $repo, string $ref, string $workDir): array
{
    $slug = extractGitHubSlug($repo);
    if ($slug !== null) {
        return downloadGitHubArchive($slug, $ref, $workDir);
    }

    return cloneRepositoryToDirectory($repo, $ref, $workDir);
}

/**
 * @return array{type: string, path: string, source: string}
 */
function downloadGitHubArchive(string $slug, string $ref, string $workDir): array
{
    $url = sprintf('https://codeload.github.com/%s/zip/%s', $slug, rawurlencode($ref));
    $archivePath = $workDir . DIRECTORY_SEPARATOR . str_replace('/', '_', $slug . '-' . $ref) . '.zip';
    downloadFile($url, $archivePath);

    $zip = new ZipArchive();
    if ($zip->open($archivePath) !== true) {
        throw new RuntimeException('Unable to open downloaded archive: ' . $archivePath);
    }

    $extractPath = $workDir . DIRECTORY_SEPARATOR . 'extracted';
    ensureDirectory($extractPath);

    if (!$zip->extractTo($extractPath)) {
        $zip->close();
        throw new RuntimeException('Failed to extract archive ' . $archivePath);
    }

    $rootPath = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) {
            continue;
        }
        $name = trim($name, '/');
        if ($name === '') {
            continue;
        }
        $rootPath = $extractPath . DIRECTORY_SEPARATOR . explode('/', $name)[0];
        break;
    }
    $zip->close();

    if ($rootPath === null || !is_dir($rootPath)) {
        throw new RuntimeException('Unable to determine extracted archive root directory.');
    }

    return [
        'type' => 'github-archive',
        'path' => $rootPath,
        'source' => $url,
    ];
}

/**
 * @return array{type: string, path: string, source: string}
 */
function cloneRepositoryToDirectory(string $repo, string $ref, string $workDir): array
{
    ensureCommandAvailable('git');
    $target = $workDir . DIRECTORY_SEPARATOR . 'repository';
    $cmd = sprintf(
        'git clone --depth 1 --branch %s %s %s',
        escapeshellarg($ref),
        escapeshellarg($repo),
        escapeshellarg($target)
    );
    runShellCommand($cmd);

    $gitDir = $target . DIRECTORY_SEPARATOR . '.git';
    if (is_dir($gitDir)) {
        deletePath($gitDir);
    }

    return [
        'type' => 'git-clone',
        'path' => $target,
        'source' => $repo . '#' . $ref,
    ];
}

function downloadFile(string $url, string $destination): void
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: HRassessv300-upgrader',
                'Accept: application/octet-stream',
            ],
            'timeout' => 60,
        ],
    ]);

    $read = @fopen($url, 'rb', false, $context);
    if ($read === false) {
        throw new RuntimeException('Unable to download archive from ' . $url);
    }

    $write = @fopen($destination, 'wb');
    if ($write === false) {
        fclose($read);
        throw new RuntimeException('Unable to write archive to ' . $destination);
    }

    $bytes = stream_copy_to_stream($read, $write);
    fclose($read);
    fclose($write);

    if ($bytes === false || $bytes === 0) {
        throw new RuntimeException('Downloaded archive is empty: ' . $url);
    }
}

function installRelease(string $sourceDir, string $appPath, array $preservePaths, bool $purgeFirst = true): void
{
    if ($purgeFirst) {
        purgeApplicationDirectory($appPath, $preservePaths);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($sourceDir) + 1);
        if (shouldSkipPath($relative, $preservePaths)) {
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

        $perms = $item->getPerms();
        @chmod($target, $perms & 0777);
    }
}

function purgeApplicationDirectory(string $appPath, array $preservePaths): void
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
        deletePath($appPath . DIRECTORY_SEPARATOR . $item);
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

function createApplicationSnapshot(string $appPath, string $backupDir, string $timestamp, array $preservePaths): string
{
    $archive = $backupDir . DIRECTORY_SEPARATOR . 'app-' . $timestamp . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create snapshot archive: ' . $archive);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($appPath) + 1);
        if (shouldSkipPath($relative, $preservePaths)) {
            continue;
        }

        $normalized = normalizeRelativePath($relative);
        if ($normalized === '') {
            continue;
        }

        if ($item->isDir()) {
            if ($zip->locateName($normalized) === false && $zip->addEmptyDir($normalized) !== true) {
                throw new RuntimeException('Unable to add directory to snapshot: ' . $normalized);
            }
        } else {
            if ($zip->addFile($item->getPathname(), $normalized) !== true) {
                throw new RuntimeException('Unable to add file to snapshot: ' . $item->getPathname());
            }
        }
    }

    $zip->close();

    return $archive;
}

function restoreApplicationSnapshot(string $appPath, string $snapshotPath, array $preservePaths): void
{
    if (!file_exists($snapshotPath)) {
        throw new RuntimeException('Snapshot file not found: ' . $snapshotPath);
    }

    purgeApplicationDirectory($appPath, $preservePaths);

    if (preg_match('/\.zip$/', $snapshotPath) === 1) {
        restoreFromZipSnapshot($appPath, $snapshotPath);
        return;
    }

    if (preg_match('/\.tar\.gz$/', $snapshotPath) === 1) {
        restoreFromTarSnapshot($appPath, $snapshotPath);
        return;
    }

    throw new RuntimeException('Unsupported snapshot format: ' . $snapshotPath);
}

function restoreFromZipSnapshot(string $appPath, string $snapshotPath): void
{
    $zip = new ZipArchive();
    if ($zip->open($snapshotPath) !== true) {
        throw new RuntimeException('Unable to open snapshot archive: ' . $snapshotPath);
    }

    $extractDir = createTempDirectory($appPath, 'snapshot_');
    try {
        if (!$zip->extractTo($extractDir)) {
            throw new RuntimeException('Failed to extract snapshot archive: ' . $snapshotPath);
        }
    } finally {
        $zip->close();
    }

    installRelease($extractDir, $appPath, [], false);
    cleanupDirectory($extractDir);
}

function restoreFromTarSnapshot(string $appPath, string $snapshotPath): void
{
    ensureCommandAvailable('tar');
    $extractDir = createTempDirectory($appPath, 'snapshot_');
    $cmd = sprintf(
        'tar -xzf %s -C %s',
        escapeshellarg($snapshotPath),
        escapeshellarg($extractDir)
    );
    runShellCommand($cmd);
    installRelease($extractDir, $appPath, [], false);
    cleanupDirectory($extractDir);
}

function backupDatabaseCli(array $dbConfig, string $backupDir, string $timestamp): string
{
    $file = $backupDir . DIRECTORY_SEPARATOR . 'db-' . $timestamp . '.sql';
    $portFragment = '';
    if ($dbConfig['port'] !== null) {
        $portFragment = ' --port=' . escapeshellarg((string)$dbConfig['port']);
    }
    $cmd = sprintf(
        'mysqldump --user=%s --password=%s --host=%s%s %s > %s',
        escapeshellarg($dbConfig['user']),
        escapeshellarg($dbConfig['password']),
        escapeshellarg($dbConfig['host']),
        $portFragment,
        escapeshellarg($dbConfig['database']),
        escapeshellarg($file)
    );
    runShellCommand($cmd);

    return $file;
}

function backupDatabaseInline(array $dbConfig, string $backupDir, string $timestamp): string
{
    global $pdo;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable for inline backup.');
    }

    $file = $backupDir . DIRECTORY_SEPARATOR . 'db-' . $timestamp . '.sql';
    $sql = upgrade_export_database($pdo);
    if (file_put_contents($file, $sql) === false) {
        throw new RuntimeException('Unable to write database export to ' . $file);
    }

    return $file;
}

function restoreDatabaseFromBackup(array $dbConfig, string $backupFile, bool $cliAvailable): void
{
    if (!is_file($backupFile)) {
        throw new RuntimeException('Database backup not found: ' . $backupFile);
    }

    if ($cliAvailable) {
        restoreDatabaseFromBackupCli($dbConfig, $backupFile);
        return;
    }

    restoreDatabaseFromBackupInline($backupFile);
}

function restoreDatabaseFromBackupCli(array $dbConfig, string $backupFile): void
{
    $portFragment = '';
    if ($dbConfig['port'] !== null) {
        $portFragment = ' --port=' . escapeshellarg((string)$dbConfig['port']);
    }
    $cmd = sprintf(
        'mysql --user=%s --password=%s --host=%s%s %s < %s',
        escapeshellarg($dbConfig['user']),
        escapeshellarg($dbConfig['password']),
        escapeshellarg($dbConfig['host']),
        $portFragment,
        escapeshellarg($dbConfig['database']),
        escapeshellarg($backupFile)
    );
    runShellCommand($cmd);
}

function restoreDatabaseFromBackupInline(string $backupFile): void
{
    global $pdo;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable for inline restore.');
    }

    $sql = file_get_contents($backupFile);
    if ($sql === false) {
        throw new RuntimeException('Unable to read database backup from ' . $backupFile);
    }

    $statements = parseSqlStatements($sql);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($statements as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
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

function parseSqlStatements(string $sql): array
{
    $withoutBlock = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $withoutLine = preg_replace('/^\s*--.*$/m', '', $withoutBlock ?? $sql);
    $normalized = $withoutLine ?? $sql;
    $parts = explode(';', $normalized);
    $statements = [];
    foreach ($parts as $part) {
        $trimmed = trim($part);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
    }

    return $statements;
}

function ensureCommandAvailable(string $command): void
{
    if (!commandAvailable($command)) {
        throw new RuntimeException(sprintf('Required command "%s" is not available in PATH.', $command));
    }
}

function commandAvailable(string $command): bool
{
    $output = @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    return is_string($output) && trim($output) !== '';
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

function ensureZipArchiveAvailable(): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is required. Install ext-zip before running upgrades.');
    }
}
