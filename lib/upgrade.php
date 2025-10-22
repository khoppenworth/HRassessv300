<?php
declare(strict_types=1);

const ADMIN_UPGRADE_DEFAULT_REPO = 'khoppenworth/HRassessv300';
const ADMIN_UPGRADE_MANIFEST_PREFIX = 'upgrade-';
const ADMIN_UPGRADE_VERSION_FILE = 'backups/installed-release.json';
const ADMIN_UPGRADE_MANUAL_BACKUP_DIR = 'backups/manual';

function upgrade_current_version(): string
{
    $info = upgrade_current_release_info();
    if ($info !== null && !empty($info['label'])) {
        return (string)$info['label'];
    }

    return '3.0.0';
}

function upgrade_current_release_info(): ?array
{
    $versionPath = base_path(ADMIN_UPGRADE_VERSION_FILE);
    if (is_file($versionPath)) {
        $contents = @file_get_contents($versionPath);
        if ($contents !== false) {
            $data = json_decode($contents, true);
            if (is_array($data)) {
                $tag = trim((string)($data['tag'] ?? ''));
                $label = trim((string)($data['name'] ?? ''));
                $installedAt = isset($data['installed_at']) && $data['installed_at'] !== ''
                    ? (string)$data['installed_at']
                    : null;
                $repo = isset($data['repo']) && $data['repo'] !== ''
                    ? (string)$data['repo']
                    : null;
                $url = isset($data['url']) && $data['url'] !== ''
                    ? (string)$data['url']
                    : null;
                if ($tag !== '' || $label !== '') {
                    return [
                        'tag' => $tag !== '' ? $tag : null,
                        'label' => $label !== '' ? $label : ($tag !== '' ? $tag : null),
                        'installed_at' => $installedAt,
                        'repo' => $repo,
                        'url' => $url,
                    ];
                }
            }
        }
    }

    foreach (upgrade_list_backups() as $backup) {
        if (($backup['status'] ?? '') !== 'success') {
            continue;
        }
        $tag = trim((string)($backup['ref'] ?? ''));
        $label = trim((string)($backup['version_label'] ?? ''));
        $url = isset($backup['release_url']) && $backup['release_url'] !== ''
            ? (string)$backup['release_url']
            : null;
        if ($tag !== '' || $label !== '') {
            return [
                'tag' => $tag !== '' ? $tag : null,
                'label' => $label !== '' ? $label : ($tag !== '' ? $tag : null),
                'installed_at' => isset($backup['completed_at']) ? (string)$backup['completed_at'] : null,
                'repo' => isset($backup['repo']) ? (string)$backup['repo'] : null,
                'url' => $url,
            ];
        }
    }

    return null;
}

function upgrade_store_installed_release(array $release): void
{
    $tag = trim((string)($release['tag'] ?? ''));
    $label = trim((string)($release['name'] ?? ''));
    if ($tag === '' && $label === '') {
        return;
    }

    $payload = [
        'tag' => $tag,
        'name' => $label,
        'repo' => isset($release['repo']) ? (string)$release['repo'] : null,
        'url' => isset($release['url']) ? (string)$release['url'] : null,
        'installed_at' => isset($release['installed_at']) && $release['installed_at'] !== ''
            ? (string)$release['installed_at']
            : date(DATE_ATOM),
    ];

    $target = base_path(ADMIN_UPGRADE_VERSION_FILE);
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory for installed release metadata.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('Unable to encode installed release metadata.');
    }

    if (file_put_contents($target, $json . "\n") === false) {
        throw new RuntimeException('Unable to write installed release metadata.');
    }
}

function upgrade_normalize_version_string(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    $normalized = preg_replace('/^v(?=\d)/i', '', $trimmed);
    if (is_string($normalized) && $normalized !== '') {
        return $normalized;
    }

    return $trimmed;
}

function upgrade_release_is_newer(string $candidate, ?string $currentTag, ?string $currentLabel = null): bool
{
    $candidateNormalized = upgrade_normalize_version_string($candidate);
    if ($candidateNormalized === '') {
        return false;
    }

    $baseline = null;
    if ($currentTag !== null && $currentTag !== '') {
        $baseline = upgrade_normalize_version_string($currentTag);
    } elseif ($currentLabel !== null && $currentLabel !== '') {
        $baseline = upgrade_normalize_version_string($currentLabel);
    }

    if ($baseline === null || $baseline === '') {
        return true;
    }

    $semverPattern = '/^[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/';
    $candidateIsSemver = preg_match($semverPattern, $candidateNormalized) === 1;
    $baselineIsSemver = preg_match($semverPattern, $baseline) === 1;

    if ($candidateIsSemver && $baselineIsSemver) {
        return version_compare($candidateNormalized, $baseline, '>');
    }

    return strcasecmp($candidateNormalized, $baseline) !== 0;
}

function upgrade_normalize_source(string $value): string
{
    $trimmed = trim(str_replace(["\r", "\n"], '', $value));
    return $trimmed;
}

function upgrade_source_from_composer(): ?string
{
    $composerPath = base_path('composer.json');
    if (!is_file($composerPath)) {
        return null;
    }
    $raw = file_get_contents($composerPath);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $name = $decoded['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return null;
    }
    $normalized = str_replace('\\', '/', $name);
    $normalized = upgrade_normalize_source($normalized);
    if (!preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $normalized)) {
        return null;
    }
    return $normalized;
}

function upgrade_effective_source(array $cfg): string
{
    $stored = upgrade_normalize_source((string)($cfg['upgrade_repo'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    $fromComposer = upgrade_source_from_composer();
    if ($fromComposer !== null) {
        return $fromComposer;
    }
    return ADMIN_UPGRADE_DEFAULT_REPO;
}

function upgrade_extract_slug(string $value): ?string
{
    $value = upgrade_normalize_source($value);
    if ($value === '') {
        return null;
    }

    $withoutGit = preg_replace('/\.git$/i', '', $value);
    if (is_string($withoutGit) && $withoutGit !== '') {
        $value = $withoutGit;
    }

    if (preg_match('#^https?://github\.com/(.+)$#i', $value, $matches)) {
        $value = $matches[1];
    } elseif (preg_match('#^github\.com/(.+)$#i', $value, $matches)) {
        $value = $matches[1];
    } elseif (preg_match('#^git@github\.com:(.+)$#i', $value, $matches)) {
        $value = $matches[1];
    }

    $value = trim($value, '/');
    if ($value === '') {
        return null;
    }

    $segments = explode('/', $value);
    if (count($segments) < 2) {
        return null;
    }

    $owner = $segments[0];
    $repo = $segments[1];
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $owner) || !preg_match('/^[A-Za-z0-9._-]+$/', $repo)) {
        return null;
    }

    return $owner . '/' . $repo;
}

function upgrade_is_valid_source(string $value): bool
{
    $value = upgrade_normalize_source($value);
    if ($value === '') {
        return true;
    }
    if (preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $value)) {
        return true;
    }
    if (preg_match('#^https?://#i', $value)) {
        $parsed = parse_url($value);
        if ($parsed === false || empty($parsed['host'])) {
            return false;
        }
        $host = strtolower((string)$parsed['host']);
        if ($host !== 'github.com' && $host !== 'www.github.com') {
            return false;
        }
        return upgrade_extract_slug($value) !== null;
    }
    if (preg_match('#^git@github\.com:#i', $value)) {
        return upgrade_extract_slug($value) !== null;
    }
    return false;
}

function upgrade_repository_argument(string $value): string
{
    $value = upgrade_normalize_source($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value) || preg_match('#^git@#i', $value)) {
        return $value;
    }
    if (preg_match('/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/', $value)) {
        return 'https://github.com/' . $value . '.git';
    }
    return $value;
}

function upgrade_fetch_latest_release(string $source, ?string $token = null): ?array
{
    $slug = upgrade_extract_slug($source);
    if ($slug === null) {
        return null;
    }

    $url = 'https://api.github.com/repos/' . $slug . '/releases/latest';
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialise GitHub release lookup.');
    }

    $headers = [
        'User-Agent: HRassessUpgrade/1.0',
        'Accept: application/vnd.github+json',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($handle);
    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('GitHub release lookup failed: ' . ($error !== '' ? $error : 'unknown error'));
    }

    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if ($status >= 400) {
        throw new RuntimeException('GitHub release lookup returned HTTP ' . $status);
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['tag_name'])) {
        throw new RuntimeException('GitHub response did not include a tag_name field.');
    }

    return [
        'tag' => (string)$decoded['tag_name'],
        'name' => isset($decoded['name']) && $decoded['name'] !== '' ? (string)$decoded['name'] : (string)$decoded['tag_name'],
        'url' => isset($decoded['html_url']) ? (string)$decoded['html_url'] : null,
    ];
}

function upgrade_list_backups(): array
{
    $backupDir = base_path('backups');
    if (!is_dir($backupDir)) {
        return [];
    }

    $manifests = glob($backupDir . DIRECTORY_SEPARATOR . ADMIN_UPGRADE_MANIFEST_PREFIX . '*.json');
    if ($manifests === false) {
        return [];
    }

    $backups = [];
    foreach ($manifests as $manifestPath) {
        $contents = @file_get_contents($manifestPath);
        if ($contents === false) {
            continue;
        }
        $data = json_decode($contents, true);
        if (!is_array($data) || empty($data['timestamp'])) {
            continue;
        }
        $backups[] = [
            'timestamp' => (string)$data['timestamp'],
            'status' => (string)($data['status'] ?? 'unknown'),
            'ref' => (string)($data['ref'] ?? ''),
            'repo' => (string)($data['repo'] ?? ''),
            'version_label' => (string)($data['version_label'] ?? ''),
            'release_url' => (string)($data['release_url'] ?? ''),
            'started_at' => isset($data['started_at']) ? (string)$data['started_at'] : null,
            'completed_at' => isset($data['completed_at']) ? (string)$data['completed_at'] : null,
            'manifest_path' => $manifestPath,
        ];
    }

    usort($backups, static function (array $a, array $b): int {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    return $backups;
}

/**
 * @param string[] $arguments
 * @return array{exit_code:int, stdout:string, stderr:string, command:array}
 */
function upgrade_run_cli(array $arguments): array
{
    $phpBinary = PHP_BINARY ?: 'php';
    $scriptPath = base_path('scripts/system_upgrade.php');
    if (!is_file($scriptPath)) {
        throw new RuntimeException('Upgrade script not found at ' . $scriptPath);
    }

    $command = array_merge([$phpBinary, $scriptPath], $arguments);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptorSpec, $pipes, base_path(''));
    if (!is_resource($process)) {
        throw new RuntimeException('Failed to start the upgrade process.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [
        'exit_code' => is_int($exitCode) ? $exitCode : 1,
        'stdout' => $stdout !== false ? $stdout : '',
        'stderr' => $stderr !== false ? $stderr : '',
        'command' => $command,
    ];
}

function upgrade_format_command(array $command): string
{
    if ($command === []) {
        return '';
    }

    return implode(' ', array_map(static function ($segment): string {
        return escapeshellarg((string)$segment);
    }, $command));
}

function upgrade_save_source(PDO $pdo, ?string $source): void
{
    if ($source === null || upgrade_normalize_source($source) === '') {
        $stmt = $pdo->prepare('INSERT INTO site_config (id, upgrade_repo) VALUES (1, NULL) ON DUPLICATE KEY UPDATE upgrade_repo=VALUES(upgrade_repo)');
        $stmt->execute();
        return;
    }

    $normalized = upgrade_normalize_source($source);
    $stmt = $pdo->prepare('INSERT INTO site_config (id, upgrade_repo) VALUES (1, ?) ON DUPLICATE KEY UPDATE upgrade_repo=VALUES(upgrade_repo)');
    $stmt->execute([$normalized]);
}

function upgrade_fetch_count(PDO $pdo, string $sql): int
{
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : null;
        return (int)($row[0] ?? $row['c'] ?? 0);
    } catch (PDOException $e) {
        error_log('Upgrade backup count failed: ' . $e->getMessage());
        return 0;
    }
}

function upgrade_export_database(PDO $pdo): string
{
    $lines = ['-- Database backup generated ' . date('c')];
    try {
        $tablesStmt = $pdo->query('SHOW TABLES');
        $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        if ($tables) {
            foreach ($tables as $tableName) {
                $table = (string)$tableName;
                if ($table === '') {
                    continue;
                }
                $safeTable = str_replace('`', '``', $table);
                $lines[] = '';
                $lines[] = 'DROP TABLE IF EXISTS `' . $safeTable . '`;';
                $createStmt = $pdo->query('SHOW CREATE TABLE `' . $safeTable . '`');
                $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($createRow) {
                    $createSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? null);
                    if ($createSql) {
                        $lines[] = $createSql . ';';
                    }
                }
                $dataStmt = $pdo->query('SELECT * FROM `' . $safeTable . '`');
                if ($dataStmt) {
                    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                        $columns = array_map(static function ($column): string {
                            return '`' . str_replace('`', '``', (string)$column) . '`';
                        }, array_keys($row));
                        $values = array_map(static function ($value) use ($pdo): string {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return $pdo->quote((string)$value);
                        }, array_values($row));
                        $lines[] = sprintf(
                            'INSERT INTO `%s` (%s) VALUES (%s);',
                            $safeTable,
                            implode(', ', $columns),
                            implode(', ', $values)
                        );
                    }
                }
            }
        } else {
            $lines[] = '-- No tables were found in the database at the time of backup.';
        }
    } catch (Throwable $e) {
        error_log('Database backup export failed: ' . $e->getMessage());
        $lines[] = '-- Failed to export database. Check server logs for details.';
    }

    return implode("\n", $lines) . "\n";
}

function upgrade_manual_backup_directory(): string
{
    return base_path(ADMIN_UPGRADE_MANUAL_BACKUP_DIR);
}

function upgrade_resolve_manual_backup_path(string $filename): ?string
{
    $normalized = trim(str_replace(['\\', "\0"], '/', $filename), '/');
    if ($normalized === '' || strpos($normalized, '..') !== false) {
        return null;
    }

    $sanitized = basename($normalized);
    if ($sanitized === '') {
        return null;
    }

    return upgrade_manual_backup_directory() . DIRECTORY_SEPARATOR . $sanitized;
}

function upgrade_create_manual_backup(PDO $pdo): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The ZipArchive extension is required to generate backups.');
    }

    $backupDir = upgrade_manual_backup_directory();
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Unable to prepare the backup directory.');
    }

    $timestamp = date('Ymd_His');
    $filename = 'system-backup-' . $timestamp . '.zip';
    $fullPath = $backupDir . DIRECTORY_SEPARATOR . $filename;

    $zip = new ZipArchive();
    if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create backup archive.');
    }

    $temporaryArtifacts = [];

    try {
        $summaryLines = [
            'System backup created on ' . date('c'),
            'Users: ' . upgrade_fetch_count($pdo, 'SELECT COUNT(*) AS c FROM users'),
            'Assessments: ' . upgrade_fetch_count($pdo, 'SELECT COUNT(*) AS c FROM questionnaire_response'),
            'Draft responses: ' . upgrade_fetch_count($pdo, "SELECT COUNT(*) AS c FROM questionnaire_response WHERE status='draft'"),
        ];
        $zip->addFromString('summary.txt', implode("\n", $summaryLines) . "\n");

        $addJson = static function (ZipArchive $archive, string $name, array $data): void {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Failed to encode backup data for ' . $name);
            }
            $archive->addFromString($name, $json . "\n");
        };

        $configStmt = $pdo->query('SELECT * FROM site_config ORDER BY id');
        $configRows = $configStmt ? $configStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $addJson($zip, 'data/site_config.json', $configRows);

        $usersStmt = $pdo->query('SELECT id, username, role, full_name, email, work_function, account_status, next_assessment_date, first_login_at, created_at FROM users ORDER BY id');
        $users = [];
        foreach ($usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            unset($row['password']);
            $users[] = $row;
        }
        $addJson($zip, 'data/users.json', $users);

        $questionnairesStmt = $pdo->query('SELECT id, title, description, created_at FROM questionnaire ORDER BY id');
        $addJson($zip, 'data/questionnaires.json', $questionnairesStmt ? $questionnairesStmt->fetchAll(PDO::FETCH_ASSOC) : []);

        $itemsStmt = $pdo->query('SELECT id, questionnaire_id, section_id, linkId, text, type, order_index, weight_percent, allow_multiple FROM questionnaire_item ORDER BY questionnaire_id, order_index');
        $addJson($zip, 'data/questionnaire_items.json', $itemsStmt ? $itemsStmt->fetchAll(PDO::FETCH_ASSOC) : []);

        $responsesStmt = $pdo->query('SELECT id, user_id, questionnaire_id, performance_period_id, status, score, reviewed_by, reviewed_at, review_comment, created_at FROM questionnaire_response ORDER BY id');
        $addJson($zip, 'data/questionnaire_responses.json', $responsesStmt ? $responsesStmt->fetchAll(PDO::FETCH_ASSOC) : []);

        $responseItemsStmt = $pdo->query('SELECT response_id, linkId, answer FROM questionnaire_response_item ORDER BY response_id, id');
        $addJson($zip, 'data/questionnaire_response_items.json', $responseItemsStmt ? $responseItemsStmt->fetchAll(PDO::FETCH_ASSOC) : []);

        $databaseDump = upgrade_export_database($pdo);
        $zip->addFromString('database/backup.sql', $databaseDump);

        $uploadsDir = base_path('assets/uploads');
        if (is_dir($uploadsDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile()) {
                    $relative = substr($fileInfo->getPathname(), strlen($uploadsDir) + 1);
                    if ($relative === false) {
                        continue;
                    }
                    $relative = str_replace('\\', '/', $relative);
                    $zip->addFile($fileInfo->getPathname(), 'uploads/' . $relative);
                }
            }
        }

        $appRoot = base_path('');
        $tempHandle = tempnam(sys_get_temp_dir(), 'appzip_');
        if ($tempHandle === false) {
            throw new RuntimeException('Unable to create temporary archive for application backup.');
        }
        $appArchivePath = $tempHandle . '.zip';
        if (!@rename($tempHandle, $appArchivePath)) {
            @unlink($tempHandle);
            throw new RuntimeException('Unable to prepare archive path for application backup.');
        }
        $temporaryArtifacts[] = $appArchivePath;

        $appZip = new ZipArchive();
        if ($appZip->open($appArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create application archive.');
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }
                $pathName = $fileInfo->getPathname();
                $relative = substr($pathName, strlen($appRoot) + 1);
                if ($relative === false || $relative === '') {
                    continue;
                }
                $relative = str_replace('\\', '/', $relative);
                if (strpos($relative, '.git/') === 0) {
                    continue;
                }
                if (strpos($relative, 'assets/backups/') === 0 || strpos($relative, 'backups/') === 0) {
                    continue;
                }
                $appZip->addFile($pathName, $relative);
            }
        } finally {
            $appZip->close();
        }

        $zip->addFile($appArchivePath, 'application.zip');

        if ($zip->close() !== true) {
            throw new RuntimeException('Failed to finalise the backup archive.');
        }
    } catch (Throwable $e) {
        $zip->close();
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
        foreach ($temporaryArtifacts as $artifact) {
            if (is_string($artifact) && is_file($artifact)) {
                @unlink($artifact);
            }
        }
        throw $e;
    }

    foreach ($temporaryArtifacts as $artifact) {
        if (is_string($artifact) && is_file($artifact)) {
            @unlink($artifact);
        }
    }

    clearstatcache(true, $fullPath);
    $archiveSize = filesize($fullPath);
    if ($archiveSize === false || $archiveSize <= 0) {
        @unlink($fullPath);
        throw new RuntimeException('The generated backup archive is empty.');
    }

    return [
        'filename' => $filename,
        'absolute_path' => $fullPath,
        'relative_path' => $filename,
        'size' => (int)$archiveSize,
    ];
}

function upgrade_stream_download(string $filePath, string $downloadName, ?int $size = null): void
{
    if (!is_file($filePath)) {
        throw new RuntimeException('Download source not found.');
    }

    if ($size === null) {
        $statSize = filesize($filePath);
        if ($statSize !== false) {
            $size = (int)$statSize;
        }
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (!headers_sent()) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($downloadName) . '"');
        if ($size !== null && $size > 0) {
            header('Content-Length: ' . (string)$size);
        }
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open download source.');
    }

    try {
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            if (function_exists('flush')) {
                flush();
            }
        }
    } finally {
        fclose($handle);
    }
}
