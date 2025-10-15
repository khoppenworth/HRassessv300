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

$resolveUpgradeRepo = static function (array $cfg): string {
    $configured = trim((string)($cfg['upgrade_repo'] ?? ''));
    if ($configured !== '') {
        return trim($configured, " \/");
    }
    $composerPath = base_path('composer.json');
    if (is_file($composerPath)) {
        $composerRaw = file_get_contents($composerPath);
        if ($composerRaw !== false) {
            $decoded = json_decode($composerRaw, true);
            if (is_array($decoded) && !empty($decoded['name']) && is_string($decoded['name'])) {
                return trim(str_replace('\\', '/', $decoded['name']));
            }
        }
    }
    return 'hrassess/hrassessv300';
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

$normalizeVersionTag = static function (string $tag): string {
    $trimmed = trim($tag);
    if ($trimmed === '') {
        return $trimmed;
    }

    $withoutRefs = preg_replace('/^refs\\/tags\\//i', '', $trimmed);
    if ($withoutRefs === null) {
        $withoutRefs = $trimmed;
    }

    $withoutCommonPrefixes = preg_replace('/^(?:release|version)[-_](?=\d)/i', '', $withoutRefs);
    if ($withoutCommonPrefixes === null) {
        $withoutCommonPrefixes = $withoutRefs;
    }

    if (preg_match('/^v(?=\d)/i', $withoutCommonPrefixes)) {
        return substr($withoutCommonPrefixes, 1);
    }

    return $withoutCommonPrefixes;
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
$upgradeState['upgrade_repo'] = $upgradeRepo;
$flashMessage = $_SESSION['admin_dashboard_flash'] ?? '';
$flashType = $_SESSION['admin_dashboard_flash_type'] ?? 'info';
unset($_SESSION['admin_dashboard_flash'], $_SESSION['admin_dashboard_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $flashType = 'info';

    switch ($action) {
        case 'check_upgrade':
            $upgradeState['last_check'] = time();
            try {
                $token = trim((string)($cfg['github_token'] ?? getenv('GITHUB_TOKEN') ?? ''));
                $release = $fetchLatestRelease($upgradeState['upgrade_repo'] ?? $upgradeRepo, $token !== '' ? $token : null);
                if ($release) {
                    $availableVersionRaw = $release['tag'];
                    $availableVersion = $normalizeVersionTag($availableVersionRaw);
                    if ($availableVersion === '') {
                        $availableVersion = $availableVersionRaw;
                    }
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
            if (empty($upgradeState['backup_ready'])) {
                $flashMessage = t($t, 'backup_required_before_upgrade', 'Download a fresh backup before installing the upgrade.');
                $flashType = 'warning';
                break;
            }
            if (empty($upgradeState['available_version'])) {
                $flashMessage = t($t, 'no_upgrade_found', 'No upgrade package is currently available.');
                $flashType = 'info';
                break;
            }
            try {
                $migrationPath = base_path('migration.sql');
                $runSqlScript($pdo, $migrationPath);
                ensure_users_schema($pdo);
                $upgradeState['current_version'] = $upgradeState['available_version'];
                $upgradeState['available_version'] = null;
                $upgradeState['available_version_label'] = null;
                $upgradeState['available_version_url'] = null;
                $upgradeState['backup_ready'] = false;
                $flashMessage = t(
                    $t,
                    'upgrade_complete',
                    'Upgrade installed successfully. Database patches have been applied.'
                );
                $flashType = 'success';
            } catch (Throwable $upgradeError) {
                error_log('Admin upgrade failed: ' . $upgradeError->getMessage());
                $flashMessage = t(
                    $t,
                    'upgrade_failed',
                    'The upgrade could not be completed. Review the logs and database permissions, then try again.'
                );
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
            <a href="<?=htmlspecialchars('https://github.com/' . ltrim($upgradeRepoDisplay, '/'), ENT_QUOTES, 'UTF-8')?>" target="_blank" rel="noopener">
              <?=htmlspecialchars($upgradeRepoDisplay, ENT_QUOTES, 'UTF-8')?>
            </a>
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
        <button type="submit" class="md-button md-primary md-elev-2" <?=(!$availableVersion || !$backupReady) ? 'disabled' : ''?>><?=t($t,'install_upgrade','Install Upgrade')?></button>
      </form>
    </div>
    <p class="md-upgrade-meta"><?=t($t,'upgrade_hint','Ensure a recent backup has been downloaded before applying upgrades.')?></p>
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
