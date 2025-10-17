<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$fetchCount = static function (PDO $pdo, string $sql): int {
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : null;
        return (int)($row['c'] ?? 0);
    } catch (PDOException $e) {
        error_log('Admin dashboard metric failed: ' . $e->getMessage());
        return 0;
    }
};

$fetchScalar = static function (PDO $pdo, string $sql, string $column = 'v'): ?string {
    try {
        $stmt = $pdo->query($sql);
        $row = $stmt ? $stmt->fetch() : null;
        if (!$row) {
            return null;
        }
        if (array_key_exists($column, $row)) {
            return $row[$column] !== null ? (string)$row[$column] : null;
        }
        $values = array_values($row);
        return isset($values[0]) && $values[0] !== null ? (string)$values[0] : null;
    } catch (PDOException $e) {
        error_log('Admin dashboard scalar metric failed: ' . $e->getMessage());
        return null;
    }
};

$runSqlScript = static function (PDO $pdo, string $path): void {
    if (!is_file($path)) {
        throw new RuntimeException('Migration script not found: ' . $path);
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read migration script: ' . $path);
    }
    $withoutBlockComments = preg_replace('/\/\*.*?\*\//s', '', $contents);
    $cleanSql = preg_replace('/^\s*--.*$/m', '', $withoutBlockComments ?? $contents);
    $statements = array_filter(array_map(static function ($statement) {
        return trim($statement);
    }, explode(';', (string)$cleanSql)), static function ($statement) {
        return $statement !== '';
    });

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
};

$extractGithubSlug = static function (string $value): ?string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $withoutGit = preg_replace('/\.git$/i', '', $trimmed);
    if ($withoutGit !== null) {
        $trimmed = $withoutGit;
    }

    if (preg_match('#^https?://#i', $trimmed)) {
        $parsed = parse_url($trimmed);
        if ($parsed === false) {
            return null;
        }
        $path = $parsed['path'] ?? '';
        $trimmed = trim((string)$path, '/');
    } elseif (stripos($trimmed, 'github.com/') === 0) {
        $trimmed = substr($trimmed, strlen('github.com/'));
        $trimmed = trim((string)$trimmed, '/');
    } else {
        $trimmed = trim($trimmed, '/');
    }

    if ($trimmed === '') {
        return null;
    }

    $segments = explode('/', $trimmed);
    if (count($segments) < 2) {
        return null;
    }

    $owner = $segments[0];
    $repo = $segments[1];

    if (!preg_match('/^[A-Za-z0-9._-]+$/', $owner) || !preg_match('/^[A-Za-z0-9._-]+$/', $repo)) {
        return null;
    }

    return $owner . '/' . $repo;
};

$normalizeUpgradeRepo = static function (string $value) use ($extractGithubSlug): string {
    $slug = $extractGithubSlug($value);
    if ($slug !== null) {
        return $slug;
    }

    return trim($value);
};

$buildRepoUrlForScript = static function (string $value) use ($extractGithubSlug): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $trimmed)) {
        return $trimmed;
    }

    if (stripos($trimmed, 'github.com/') === 0) {
        return 'https://' . ltrim($trimmed, '/');
    }

    $slug = $extractGithubSlug($trimmed);
    if ($slug !== null) {
        return 'https://github.com/' . $slug . '.git';
    }

    return $trimmed;
};

$listUpgradeBackups = static function (): array {
    $backupDir = base_path('backups');
    if (!is_dir($backupDir)) {
        return [];
    }

    $manifests = glob($backupDir . DIRECTORY_SEPARATOR . 'upgrade-*.json');
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
        $timestamp = (string)$data['timestamp'];
        $backups[] = [
            'timestamp' => $timestamp,
            'status' => (string)($data['status'] ?? 'unknown'),
            'ref' => (string)($data['ref'] ?? ''),
            'repo' => (string)($data['repo'] ?? ''),
            'started_at' => isset($data['started_at']) ? (string)$data['started_at'] : null,
            'completed_at' => isset($data['completed_at']) ? (string)$data['completed_at'] : null,
            'manifest_path' => $manifestPath,
        ];
    }

    usort($backups, static function (array $a, array $b): int {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    return $backups;
};

$runSystemUpgradeCommand = static function (array $arguments): array {
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
};

$formatCommandForDisplay = static function (array $command): string {
    if ($command === []) {
        return '';
    }

    return implode(' ', array_map(static function ($segment): string {
        return escapeshellarg((string)$segment);
    }, $command));
};

$resolveUpgradeRepo = static function (array $cfg) use ($normalizeUpgradeRepo): string {
    $configured = $normalizeUpgradeRepo((string)($cfg['upgrade_repo'] ?? ''));
    if ($configured !== '') {
        return trim($configured, " \/");
    }
    $composerPath = base_path('composer.json');
    if (is_file($composerPath)) {
        $composerRaw = file_get_contents($composerPath);
        if ($composerRaw !== false) {
            $decoded = json_decode($composerRaw, true);
            if (is_array($decoded) && !empty($decoded['name']) && is_string($decoded['name'])) {
                return $normalizeUpgradeRepo(str_replace('\\', '/', $decoded['name']));
            }
        }
    }
    return 'khoppenworth/HRassessv300';
};

$fetchLatestRelease = static function (string $repo, ?string $token = null): ?array {
    $slug = trim($repo);
    if ($slug === '') {
        return null;
    }
    $slug = preg_replace('#^https?://github\\.com/#i', '', $slug);
    $slug = trim((string)$slug, '/');
    if ($slug === '') {
        return null;
    }
    $url = 'https://api.github.com/repos/' . $slug . '/releases/latest';
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialise GitHub release request.');
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
};

$currentVersion = '3.0.0';
$upgradeRepo = $resolveUpgradeRepo($cfg);
$upgradeDefaults = [
    'current_version' => $currentVersion,
    'available_version' => null,
    'available_version_label' => null,
    'available_version_url' => null,
    'last_check' => null,
    'backup_ready' => false,
    'last_backup_at' => null,
    'last_backup_path' => null,
    'upgrade_repo' => $upgradeRepo,
];
$upgradeState = array_replace($upgradeDefaults, $_SESSION['admin_upgrade_state'] ?? []);
$upgradeState['upgrade_repo'] = $normalizeUpgradeRepo((string)($upgradeState['upgrade_repo'] ?? $upgradeRepo));
if ($upgradeState['upgrade_repo'] === '') {
    $upgradeState['upgrade_repo'] = $upgradeRepo;
}
$flashMessage = $_SESSION['admin_dashboard_flash'] ?? '';
$flashType = $_SESSION['admin_dashboard_flash_type'] ?? 'info';
unset($_SESSION['admin_dashboard_flash'], $_SESSION['admin_dashboard_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $flashType = 'info';

    switch ($action) {
        case 'update_upgrade_repo':
            $inputRepo = trim((string)($_POST['upgrade_repo'] ?? ''));
            $normalizedRepo = $normalizeUpgradeRepo($inputRepo);
            if ($normalizedRepo === '' || strpos($normalizedRepo, '/') === false) {
                $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid GitHub owner/repository slug.');
                $flashType = 'error';
                break;
            }
            try {
                $stmt = $pdo->prepare('UPDATE site_config SET upgrade_repo=? WHERE id=1');
                $stmt->execute([$normalizedRepo]);
                $cfg = get_site_config($pdo);
                $upgradeRepo = $resolveUpgradeRepo($cfg);
                $upgradeState['upgrade_repo'] = $normalizeUpgradeRepo($upgradeRepo);
                $upgradeState['available_version'] = null;
                $upgradeState['available_version_label'] = null;
                $upgradeState['available_version_url'] = null;
                $flashMessage = t($t, 'upgrade_repo_saved', 'Upgrade source saved.');
                $flashType = 'success';
            } catch (Throwable $saveError) {
                error_log('Admin upgrade repo save failed: ' . $saveError->getMessage());
                $flashMessage = t($t, 'upgrade_repo_save_failed', 'Unable to save the upgrade source. Please try again.');
                $flashType = 'error';
            }
            break;

        case 'check_upgrade':
            $upgradeState['last_check'] = time();
            try {
                $token = trim((string)($cfg['github_token'] ?? getenv('GITHUB_TOKEN') ?? ''));
                $release = $fetchLatestRelease($upgradeState['upgrade_repo'] ?? $upgradeRepo, $token !== '' ? $token : null);
                if ($release) {
                    $availableVersion = $release['tag'];
                    $availableLabel = $release['name'];
                    $availableUrl = $release['url'];
                    if (version_compare($availableVersion, (string)$upgradeState['current_version'], '>')) {
                        $upgradeState['available_version'] = $availableVersion;
                        $upgradeState['available_version_label'] = $availableLabel;
                        $upgradeState['available_version_url'] = $availableUrl;
                        $upgradeState['backup_ready'] = false;
                        $flashMessage = sprintf(
                            t($t, 'upgrade_available', 'Version %s is available for installation.'),
                            $availableLabel
                        );
                        $flashType = 'success';
                    } else {
                        $upgradeState['available_version'] = null;
                        $upgradeState['available_version_label'] = $availableLabel;
                        $upgradeState['available_version_url'] = $availableUrl;
                        $flashMessage = t($t, 'upgrade_latest', 'You are already on the latest version.');
                        $flashType = 'success';
                    }
                } else {
                    $flashMessage = t($t, 'upgrade_check_no_release', 'No GitHub releases were found for the configured repository.');
                    $flashType = 'warning';
                }
            } catch (Throwable $upgradeCheckError) {
                error_log('Admin upgrade check failed: ' . $upgradeCheckError->getMessage());
                $flashMessage = t($t, 'upgrade_check_failed', 'Unable to reach GitHub to verify the latest release. Please try again later.');
                $flashType = 'error';
            }
            break;

        case 'download_backups':
            if (!class_exists('ZipArchive')) {
                $flashMessage = t($t, 'backup_failed', 'The ZipArchive extension is required to generate backups.');
                $flashType = 'error';
                break;
            }

            $backupDir = base_path('assets/backups');
            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                $flashMessage = t($t, 'backup_failed', 'Unable to prepare the backup directory.');
                $flashType = 'error';
                break;
            }

            $timestamp = date('Ymd_His');
            $filename = 'system-backup-' . $timestamp . '.zip';
            $fullPath = $backupDir . '/' . $filename;
            $tempFiles = [];
            $backupGenerated = false;

            $archiveSize = 0;
            try {
                $zip = new ZipArchive();
                if ($zip->open($fullPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Unable to create backup archive.');
                }

                $summaryLines = [
                    'System backup created on ' . date('c'),
                    'Users: ' . $fetchCount($pdo, 'SELECT COUNT(*) c FROM users'),
                    'Assessments: ' . $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire_response'),
                    'Draft responses: ' . $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='draft'"),
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

                $databaseDump = '-- Database backup generated ' . date('c') . "\n";
                try {
                    $tablesStmt = $pdo->query('SHOW TABLES');
                    $tables = $tablesStmt ? $tablesStmt->fetchAll(PDO::FETCH_COLUMN) : [];
                    if ($tables) {
                        $dumpLines = [];
                        $dumpLines[] = '-- Database backup generated ' . date('c');
                        foreach ($tables as $tableName) {
                            $table = (string)$tableName;
                            if ($table === '') {
                                continue;
                            }
                            $safeTable = str_replace('`', '``', $table);
                            $dumpLines[] = '';
                            $dumpLines[] = 'DROP TABLE IF EXISTS `' . $safeTable . '`;';
                            $createStmt = $pdo->query('SHOW CREATE TABLE `' . $safeTable . '`');
                            $createRow = $createStmt ? $createStmt->fetch(PDO::FETCH_ASSOC) : null;
                            if ($createRow) {
                                $createSql = $createRow['Create Table'] ?? ($createRow['Create View'] ?? null);
                                if ($createSql) {
                                    $dumpLines[] = $createSql . ';';
                                }
                            }
                            $dataStmt = $pdo->query('SELECT * FROM `' . $safeTable . '`');
                            if ($dataStmt) {
                                while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
                                    $columns = array_map(static function ($column) {
                                        return '`' . str_replace('`', '``', (string)$column) . '`';
                                    }, array_keys($row));
                                    $values = array_map(static function ($value) use ($pdo) {
                                        if ($value === null) {
                                            return 'NULL';
                                        }
                                        return $pdo->quote((string)$value);
                                    }, array_values($row));
                                    $dumpLines[] = sprintf(
                                        'INSERT INTO `%s` (%s) VALUES (%s);',
                                        $safeTable,
                                        implode(', ', $columns),
                                        implode(', ', $values)
                                    );
                                }
                            }
                        }
                        $databaseDump = implode("\n", $dumpLines) . "\n";
                    } else {
                        $databaseDump .= "-- No tables were found in the database at the time of backup.\n";
                    }
                } catch (Throwable $dumpError) {
                    error_log('Database backup export failed: ' . $dumpError->getMessage());
                    $databaseDump .= "-- Failed to export database. Check server logs for details.\n";
                }
                $zip->addFromString('database/backup.sql', $databaseDump);

                $uploadsDir = base_path('assets/uploads');
                if (is_dir($uploadsDir)) {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($iterator as $fileInfo) {
                        if ($fileInfo->isFile()) {
                            $relative = substr($fileInfo->getPathname(), strlen($uploadsDir) + 1);
                            $relative = str_replace('\\', '/', $relative);
                            $zip->addFile($fileInfo->getPathname(), 'uploads/' . $relative);
                        }
                    }
                }

                try {
                    $appRoot = base_path('');
                    $tempArchive = tempnam(sys_get_temp_dir(), 'appzip_');
                    if ($tempArchive === false) {
                        throw new RuntimeException('Unable to create temporary archive for application backup.');
                    }
                    $appArchivePath = $tempArchive . '.zip';
                    if (!@rename($tempArchive, $appArchivePath)) {
                        @unlink($tempArchive);
                        throw new RuntimeException('Unable to prepare archive path for application backup.');
                    }
                    $tempFiles[] = $appArchivePath;
                    $appZip = new ZipArchive();
                    if ($appZip->open($appArchivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                        throw new RuntimeException('Unable to create application archive.');
                    }
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($appRoot, FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($iterator as $fileInfo) {
                        if (!$fileInfo->isFile()) {
                            continue;
                        }
                        $pathName = $fileInfo->getPathname();
                        if (strpos($pathName, $backupDir) === 0) {
                            continue;
                        }
                        $relative = substr($pathName, strlen($appRoot) + 1);
                        if ($relative === false) {
                            continue;
                        }
                        $relative = str_replace('\\', '/', $relative);
                        if ($relative === '' || strpos($relative, '.git/') === 0) {
                            continue;
                        }
                        if (strpos($relative, 'assets/backups/') === 0 || strpos($relative, 'backups/') === 0) {
                            continue;
                        }
                        $appZip->addFile($pathName, $relative);
                    }
                    $appZip->close();
                    $zip->addFile($appArchivePath, 'application.zip');
                } catch (Throwable $archiveError) {
                    if (isset($appZip) && $appZip instanceof ZipArchive) {
                        $appZip->close();
                    }
                    error_log('Application archive export failed: ' . $archiveError->getMessage());
                }

                $closeResult = $zip->close();
                if ($closeResult !== true) {
                    throw new RuntimeException('Failed to finalise the backup archive.');
                }
                clearstatcache(true, $fullPath);
                $archiveSize = filesize($fullPath);
                if ($archiveSize === false || $archiveSize <= 0) {
                    throw new RuntimeException('The generated backup archive is empty.');
                }
                $backupGenerated = true;
            } catch (Throwable $backupError) {
                if (isset($zip) && $zip instanceof ZipArchive) {
                    $zip->close();
                }
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
                error_log('Admin backup failed: ' . $backupError->getMessage());
                $flashMessage = t($t, 'backup_failed', 'Unable to generate the backup archive.');
                $flashType = 'error';
            }

            foreach ($tempFiles as $tempFile) {
                if (is_string($tempFile) && is_file($tempFile)) {
                    @unlink($tempFile);
                }
            }

            if (!$backupGenerated) {
                break;
            }

            $upgradeState['backup_ready'] = true;
            $upgradeState['last_backup_at'] = time();
            $upgradeState['last_backup_path'] = 'assets/backups/' . $filename;

            $_SESSION['admin_upgrade_state'] = $upgradeState;
            $_SESSION['admin_dashboard_flash'] = t($t, 'backup_ready_message', 'System backup archive created successfully.');
            $_SESSION['admin_dashboard_flash_type'] = 'success';

            session_write_close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
            header('Content-Length: ' . (string)$archiveSize);
            header('Cache-Control: private, max-age=0');
            readfile($fullPath);
            exit;

        case 'install_upgrade':
            $repoForUpgrade = $normalizeUpgradeRepo($upgradeState['upgrade_repo'] ?? $upgradeRepo);
            if ($repoForUpgrade === '' || strpos($repoForUpgrade, '/') === false) {
                $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid GitHub owner/repository slug.');
                $flashType = 'error';
                break;
            }
            $repoUrl = $buildRepoUrlForScript($repoForUpgrade);
            if ($repoUrl === '') {
                $flashMessage = t($t, 'upgrade_repo_invalid', 'Enter a valid GitHub owner/repository slug.');
                $flashType = 'error';
                break;
            }
            $arguments = ['--action=upgrade', '--repo=' . $repoUrl, '--latest-release'];
            $commandForLog = array_merge([PHP_BINARY ?: 'php', base_path('scripts/system_upgrade.php')], $arguments);
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            try {
                $result = $runSystemUpgradeCommand($arguments);
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'upgrade',
                    'timestamp' => time(),
                    'command' => $formatCommandForDisplay($result['command']),
                    'stdout' => trim((string)$result['stdout']),
                    'stderr' => trim((string)$result['stderr']),
                    'exit_code' => $result['exit_code'],
                ];
                if ((int)$result['exit_code'] === 0) {
                    if (!empty($upgradeState['available_version'])) {
                        $upgradeState['current_version'] = $upgradeState['available_version'];
                    }
                    $upgradeState['available_version'] = null;
                    $upgradeState['available_version_label'] = null;
                    $upgradeState['available_version_url'] = null;
                    $upgradeState['backup_ready'] = false;
                    $flashMessage = t($t, 'upgrade_command_success', 'Upgrade command completed successfully. Review the logs for details.');
                    $flashType = 'success';
                } else {
                    $flashMessage = t($t, 'upgrade_command_failed', 'The upgrade command returned a non-zero exit code. Review the log for details.');
                    $flashType = 'error';
                }
            } catch (Throwable $upgradeError) {
                error_log('Admin upgrade failed: ' . $upgradeError->getMessage());
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'upgrade',
                    'timestamp' => time(),
                    'command' => $formatCommandForDisplay($commandForLog),
                    'stdout' => '',
                    'stderr' => $upgradeError->getMessage(),
                    'exit_code' => -1,
                ];
                $flashMessage = t(
                    $t,
                    'upgrade_failed',
                    'The upgrade could not be completed. Review the logs and database permissions, then try again.'
                );
                $flashType = 'error';
            }
            break;

        case 'restore_backup':
            $backupId = trim((string)($_POST['backup_id'] ?? ''));
            $restoreDb = isset($_POST['restore_db']);
            if ($backupId === '') {
                $flashMessage = t($t, 'restore_backup_invalid', 'Select a backup before attempting a restore.');
                $flashType = 'error';
                break;
            }
            if (!preg_match('/^[0-9]{8}_[0-9]{6}$/', $backupId)) {
                $flashMessage = t($t, 'restore_backup_invalid', 'Select a backup before attempting a restore.');
                $flashType = 'error';
                break;
            }
            $manifestPath = base_path('backups/upgrade-' . $backupId . '.json');
            if (!is_file($manifestPath)) {
                $flashMessage = t($t, 'restore_backup_missing', 'The selected backup metadata could not be found on the server.');
                $flashType = 'error';
                break;
            }
            $arguments = ['--action=downgrade', '--backup-id=' . $backupId];
            if ($restoreDb) {
                $arguments[] = '--restore-db';
            }
            $restoreCommandForLog = array_merge([PHP_BINARY ?: 'php', base_path('scripts/system_upgrade.php')], $arguments);
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            try {
                $result = $runSystemUpgradeCommand($arguments);
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'restore',
                    'timestamp' => time(),
                    'command' => $formatCommandForDisplay($result['command']),
                    'stdout' => trim((string)$result['stdout']),
                    'stderr' => trim((string)$result['stderr']),
                    'exit_code' => $result['exit_code'],
                ];
                if ((int)$result['exit_code'] === 0) {
                    $upgradeState['available_version'] = null;
                    $upgradeState['available_version_label'] = null;
                    $upgradeState['available_version_url'] = null;
                    $flashMessage = t($t, 'restore_backup_success', 'Backup restoration completed successfully.');
                    $flashType = 'success';
                } else {
                    $flashMessage = t($t, 'restore_backup_failed', 'The restore command failed. Review the log for details.');
                    $flashType = 'error';
                }
            } catch (Throwable $restoreError) {
                error_log('Admin restore failed: ' . $restoreError->getMessage());
                $_SESSION['admin_upgrade_log'] = [
                    'type' => 'restore',
                    'timestamp' => time(),
                    'command' => $formatCommandForDisplay($restoreCommandForLog),
                    'stdout' => '',
                    'stderr' => $restoreError->getMessage(),
                    'exit_code' => -1,
                ];
                $flashMessage = t($t, 'restore_backup_failed', 'The restore command failed. Review the log for details.');
                $flashType = 'error';
            }
            break;

        default:
            $flashMessage = t($t, 'unknown_action', 'Unknown dashboard action.');
            $flashType = 'error';
            break;
    }

    $_SESSION['admin_upgrade_state'] = $upgradeState;
    $_SESSION['admin_dashboard_flash'] = $flashMessage;
    $_SESSION['admin_dashboard_flash_type'] = $flashType;
    header('Location: ' . url_for('admin/dashboard.php'));
    exit;
}

$_SESSION['admin_upgrade_state'] = $upgradeState;

$upgradeLog = $_SESSION['admin_upgrade_log'] ?? null;
unset($_SESSION['admin_upgrade_log']);

$upgradeBackups = $listUpgradeBackups();

$users = $fetchCount($pdo, 'SELECT COUNT(*) c FROM users');
$activeUsers = $fetchCount($pdo, "SELECT COUNT(*) c FROM users WHERE account_status='active'");
$pendingUsers = $fetchCount($pdo, "SELECT COUNT(*) c FROM users WHERE account_status='pending'");
$disabledUsers = $fetchCount($pdo, "SELECT COUNT(*) c FROM users WHERE account_status='disabled'");
$totalQuestionnaires = $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire');
$totalResponses = $fetchCount($pdo, 'SELECT COUNT(*) c FROM questionnaire_response');
$assessmentsLast30 = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$draftResponses = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='draft'");
$submittedResponses = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='submitted'");
$approvedResponses = $fetchCount($pdo, "SELECT COUNT(*) c FROM questionnaire_response WHERE status='approved'");
$avgScoreRaw = $fetchScalar($pdo, 'SELECT AVG(score) v FROM questionnaire_response WHERE score IS NOT NULL');
$maxScoreRaw = $fetchScalar($pdo, 'SELECT MAX(score) v FROM questionnaire_response WHERE score IS NOT NULL');
$minScoreRaw = $fetchScalar($pdo, 'SELECT MIN(score) v FROM questionnaire_response WHERE score IS NOT NULL');
$latestSubmissionRaw = $fetchScalar($pdo, 'SELECT MAX(created_at) v FROM questionnaire_response');

$avgScoreDisplay = $avgScoreRaw !== null ? number_format((float)$avgScoreRaw, 1) . '%' : '—';
$maxScoreDisplay = $maxScoreRaw !== null ? number_format((float)$maxScoreRaw, 0) . '%' : '—';
$minScoreDisplay = $minScoreRaw !== null ? number_format((float)$minScoreRaw, 0) . '%' : '—';
$latestSubmissionDisplay = '—';
if ($latestSubmissionRaw) {
    try {
        $dt = new DateTime($latestSubmissionRaw);
        $latestSubmissionDisplay = $dt->format('M j, Y g:i a');
    } catch (Exception $e) {
        $latestSubmissionDisplay = $latestSubmissionRaw;
    }
}

$availableVersion = $upgradeState['available_version'] ?? null;
$availableVersionLabel = $upgradeState['available_version_label'] ?? $availableVersion;
$availableVersionUrl = $upgradeState['available_version_url'] ?? null;
$backupReady = !empty($upgradeState['backup_ready']);
$lastCheckDisplay = !empty($upgradeState['last_check']) ? date('M j, Y g:i a', (int)$upgradeState['last_check']) : null;
$lastBackupDisplay = !empty($upgradeState['last_backup_at']) ? date('M j, Y g:i a', (int)$upgradeState['last_backup_at']) : null;
$backupDownloadUrl = !empty($upgradeState['last_backup_path']) ? asset_url($upgradeState['last_backup_path']) : null;
$upgradeRepoDisplay = $upgradeState['upgrade_repo'] ?? $upgradeRepo;
$upgradeRepoLink = '';
$repoSlugForLink = $extractGithubSlug($upgradeRepoDisplay);
if ($repoSlugForLink !== null) {
    $upgradeRepoLink = 'https://github.com/' . $repoSlugForLink;
} elseif (is_string($upgradeRepoDisplay) && preg_match('#^https?://#i', (string)$upgradeRepoDisplay)) {
    $upgradeRepoLink = (string)$upgradeRepoDisplay;
}
$selectedBackupId = $upgradeBackups[0]['timestamp'] ?? '';
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'admin_dashboard','Admin Dashboard'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.php')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <?php if ($flashMessage): ?>
    <?php
      $alertClass = '';
      if ($flashType === 'success') {
          $alertClass = ' success';
      } elseif ($flashType === 'error') {
          $alertClass = ' error';
      } elseif ($flashType === 'warning') {
          $alertClass = ' warning';
      }
    ?>
    <div class="md-alert<?=$alertClass?>"><?=htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8')?></div>
  <?php endif; ?>

  <div class="md-card md-elev-2 md-dashboard-card md-dashboard-card--upgrade">
    <h2 class="md-card-title"><?=t($t,'system_upgrade','System Upgrade')?></h2>
    <div class="md-upgrade-status">
        <div><strong><?=t($t,'current_version','Current version')?>:</strong> <span class="md-status-badge success"><?=htmlspecialchars((string)$upgradeState['current_version'], ENT_QUOTES, 'UTF-8')?></span></div>
        <div><strong><?=t($t,'available_version','Available version')?>:</strong>
          <?php if ($availableVersion): ?>
            <span class="md-status-badge warning">
              <?php if ($availableVersionUrl): ?>
                <a href="<?=htmlspecialchars($availableVersionUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener">
                  <?=htmlspecialchars($availableVersionLabel ?? $availableVersion, ENT_QUOTES, 'UTF-8')?>
                </a>
              <?php else: ?>
                <?=htmlspecialchars($availableVersionLabel ?? $availableVersion, ENT_QUOTES, 'UTF-8')?>
              <?php endif; ?>
            </span>
          <?php else: ?>
            <span class="md-status-badge success"><?=t($t,'no_update_required','Up to date')?></span>
            <?php if ($availableVersionLabel): ?>
              <span class="md-upgrade-meta md-upgrade-meta--inline">
                <?=t($t,'latest_release_label','Latest release')?>:
                <?php if ($availableVersionUrl): ?>
                  <a href="<?=htmlspecialchars($availableVersionUrl, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener">
                    <?=htmlspecialchars($availableVersionLabel, ENT_QUOTES, 'UTF-8')?>
                  </a>
                <?php else: ?>
                  <?=htmlspecialchars($availableVersionLabel, ENT_QUOTES, 'UTF-8')?>
                <?php endif; ?>
              </span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <div><strong><?=t($t,'backup_status','Backup status')?>:</strong>
          <span class="md-status-badge <?=$backupReady ? 'success' : 'warning'?>"><?=$backupReady ? t($t,'backup_ready','Backup ready') : t($t,'backup_required','Backup required')?></span>
        </div>
        <?php if ($upgradeRepoDisplay): ?>
          <div class="md-upgrade-meta">
            <?=t($t,'release_source','Release source')?>:
            <?php if ($upgradeRepoLink): ?>
              <a href="<?=htmlspecialchars($upgradeRepoLink, ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener">
                <?=htmlspecialchars($upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>
              </a>
            <?php else: ?>
              <?=htmlspecialchars($upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <?php if ($lastCheckDisplay): ?>
          <div class="md-upgrade-meta"><?=t($t,'last_checked','Last checked:')?> <?=htmlspecialchars($lastCheckDisplay, ENT_QUOTES, 'UTF-8')?></div>
        <?php endif; ?>
        <?php if ($lastBackupDisplay): ?>
          <div class="md-upgrade-meta">
            <?=t($t,'last_backup','Last backup:')?> <?=htmlspecialchars($lastBackupDisplay, ENT_QUOTES, 'UTF-8')?>
            <?php if ($backupDownloadUrl): ?>
              · <a href="<?=htmlspecialchars($backupDownloadUrl, ENT_QUOTES, 'UTF-8')?>" class="md-appbar-link" download><?=t($t,'download_backup','Download backup')?></a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
    </div>
    <form method="post" class="md-upgrade-config">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="update_upgrade_repo">
      <label class="md-field">
        <span><?=t($t,'upgrade_repo_label','Release source')?></span>
        <input type="text" name="upgrade_repo" value="<?=htmlspecialchars((string)$upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>" placeholder="owner/repository">
      </label>
      <p class="md-upgrade-meta"><?=t($t,'upgrade_repo_hint','Specify the GitHub owner/repository slug used to check for releases (for example, khoppenworth/HRassessv300).')?></p>
      <button type="submit" class="md-button md-outline md-compact"><?=t($t,'save_upgrade_source','Save source')?></button>
    </form>
    <div class="md-upgrade-actions">
      <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="check_upgrade">
        <button type="submit" class="md-button md-outline md-elev-1"><?=t($t,'check_for_upgrade','Check for Upgrade')?></button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="download_backups">
        <button type="submit" class="md-button md-elev-1"><?=t($t,'download_backups','Download backups')?></button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="action" value="install_upgrade">
        <button type="submit" class="md-button md-primary md-elev-2" <?=($upgradeRepoDisplay === '' ? 'disabled' : '')?>><?=t($t,'install_upgrade','Install Upgrade')?></button>
      </form>
    </div>
    <p class="md-upgrade-meta"><?=t($t,'upgrade_hint_script','The upgrade script automatically creates backups before applying changes. Download manual backups whenever you need an extra copy.')?></p>
    <?php if ($upgradeLog): ?>
      <div class="md-upgrade-log">
        <h3 class="md-subhead"><?=t($t,'upgrade_log_heading','Recent upgrade activity')?></h3>
        <p class="md-upgrade-meta"><?=t($t,'upgrade_log_hint','Results from the most recent upgrade or restore command.')?></p>
        <?php if (!empty($upgradeLog['timestamp'])): ?>
          <div class="md-upgrade-meta"><?=t($t,'upgrade_log_timestamp','Run at:')?> <?=htmlspecialchars(date('M j, Y g:i a', (int)$upgradeLog['timestamp']), ENT_QUOTES, 'UTF-8')?></div>
        <?php endif; ?>
        <?php if (!empty($upgradeLog['command'])): ?>
          <div class="md-upgrade-meta"><strong><?=t($t,'upgrade_command_label','Executed command')?>:</strong> <code><?=htmlspecialchars($upgradeLog['command'], ENT_QUOTES, 'UTF-8')?></code></div>
        <?php endif; ?>
        <div class="md-upgrade-meta"><strong><?=t($t,'upgrade_exit_code_label','Exit code')?>:</strong> <span class="md-status-badge <?=((int)($upgradeLog['exit_code'] ?? 1) === 0) ? 'success' : 'error'?>"><?=htmlspecialchars((string)($upgradeLog['exit_code'] ?? '—'), ENT_QUOTES, 'UTF-8')?></span></div>
        <?php if (!empty($upgradeLog['stdout'])): ?>
          <div class="md-upgrade-log-block">
            <strong><?=t($t,'upgrade_stdout_label','Output')?>:</strong>
            <pre class="md-code-block"><?=htmlspecialchars($upgradeLog['stdout'], ENT_QUOTES, 'UTF-8')?></pre>
          </div>
        <?php endif; ?>
        <?php if (!empty($upgradeLog['stderr'])): ?>
          <div class="md-upgrade-log-block">
            <strong><?=t($t,'upgrade_stderr_label','Errors')?>:</strong>
            <pre class="md-code-block"><?=htmlspecialchars($upgradeLog['stderr'], ENT_QUOTES, 'UTF-8')?></pre>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="md-upgrade-backups">
      <h3 class="md-subhead"><?=t($t,'upgrade_recent_backups','Upgrade backups')?></h3>
      <?php if ($upgradeBackups): ?>
        <form method="post" class="md-upgrade-restore-form">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="action" value="restore_backup">
          <label class="md-field">
            <span><?=t($t,'restore_backup_label','Select backup')?></span>
            <select name="backup_id">
              <?php foreach ($upgradeBackups as $backupMeta): ?>
                <?php
                  $optionLabel = $backupMeta['timestamp'];
                  if (!empty($backupMeta['status'])) {
                      $optionLabel .= ' · ' . ucfirst($backupMeta['status']);
                  }
                  if (!empty($backupMeta['ref'])) {
                      $optionLabel .= ' · ' . $backupMeta['ref'];
                  }
                ?>
                <option value="<?=htmlspecialchars($backupMeta['timestamp'], ENT_QUOTES, 'UTF-8')?>" <?=$backupMeta['timestamp'] === $selectedBackupId ? 'selected' : ''?>><?=htmlspecialchars($optionLabel, ENT_QUOTES, 'UTF-8')?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="md-control">
            <label>
              <input type="checkbox" name="restore_db" value="1">
              <span><?=t($t,'restore_database','Restore database')?></span>
            </label>
          </div>
          <p class="md-upgrade-meta"><?=t($t,'restore_database_hint','Also restore the database from the selected backup.')?></p>
          <button type="submit" class="md-button md-outline md-elev-1"><?=t($t,'restore_backup','Restore backup')?></button>
        </form>
        <ul class="md-upgrade-meta-list">
          <?php foreach (array_slice($upgradeBackups, 0, 5) as $backupMeta): ?>
            <li>
              <strong><?=htmlspecialchars($backupMeta['timestamp'], ENT_QUOTES, 'UTF-8')?></strong>
              <span>· <?=htmlspecialchars(ucfirst($backupMeta['status']), ENT_QUOTES, 'UTF-8')?></span>
              <?php if (!empty($backupMeta['ref'])): ?>
                <span>· <?=htmlspecialchars($backupMeta['ref'], ENT_QUOTES, 'UTF-8')?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="md-upgrade-meta"><?=t($t,'no_backups_available','No upgrade backups are available yet.')?></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="md-dashboard-grid">
    <div class="md-card md-elev-2 md-dashboard-card">
      <h2 class="md-card-title"><?=t($t,'system_snapshot','System Snapshot')?></h2>
      <ul class="md-stat-list">
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'total_users','Total accounts')?></span><span class="md-stat-value"><?=$users?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'active_users','Active')?></span><span class="md-stat-value"><?=$activeUsers?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'pending_accounts','Pending approvals')?></span><span class="md-stat-value"><?=$pendingUsers?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'disabled_accounts','Disabled')?></span><span class="md-stat-value"><?=$disabledUsers?></span></li>
      </ul>
      <p class="md-upgrade-meta"><?=t($t,'snapshot_hint','Review pending or disabled accounts frequently to maintain workforce readiness.')?></p>
    </div>

    <div class="md-card md-elev-2 md-dashboard-card">
      <h2 class="md-card-title"><?=t($t,'assessment_activity','Assessment Activity')?></h2>
      <ul class="md-stat-list">
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'questionnaires_count','Questionnaires')?></span><span class="md-stat-value"><?=$totalQuestionnaires?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'responses_count','All responses')?></span><span class="md-stat-value"><?=$totalResponses?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'responses_last_30','Responses (30 days)')?></span><span class="md-stat-value"><?=$assessmentsLast30?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'draft_responses','Draft responses')?></span><span class="md-stat-value"><?=$draftResponses?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'pending_reviews','Pending reviews')?></span><span class="md-stat-value"><?=$submittedResponses?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'approved_responses','Approved responses')?></span><span class="md-stat-value"><?=$approvedResponses?></span></li>
      </ul>
    </div>

    <div class="md-card md-elev-2 md-dashboard-card">
      <h2 class="md-card-title"><?=t($t,'performance_insights','Performance Insights')?></h2>
      <ul class="md-stat-list">
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'average_score','Average score')?></span><span class="md-stat-value"><?=$avgScoreDisplay?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'highest_score','Highest score')?></span><span class="md-stat-value"><?=$maxScoreDisplay?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'lowest_score','Lowest score')?></span><span class="md-stat-value"><?=$minScoreDisplay?></span></li>
        <li class="md-stat-item"><span class="md-stat-label"><?=t($t,'latest_submission','Latest submission')?></span><span class="md-stat-value" style="font-size: 1rem;"><?=htmlspecialchars($latestSubmissionDisplay, ENT_QUOTES, 'UTF-8')?></span></li>
      </ul>
      <p class="md-upgrade-meta"><?=t($t,'insight_hint','Use these trends to target coaching and professional development activities.')?></p>
    </div>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>
