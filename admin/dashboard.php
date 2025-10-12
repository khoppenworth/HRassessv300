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

$currentVersion = '3.0.0';
$upgradeDefaults = [
    'current_version' => $currentVersion,
    'available_version' => null,
    'last_check' => null,
    'backup_ready' => false,
    'last_backup_at' => null,
    'last_backup_path' => null,
];
$upgradeState = array_replace($upgradeDefaults, $_SESSION['admin_upgrade_state'] ?? []);
$flashMessage = $_SESSION['admin_dashboard_flash'] ?? '';
$flashType = $_SESSION['admin_dashboard_flash_type'] ?? 'info';
unset($_SESSION['admin_dashboard_flash'], $_SESSION['admin_dashboard_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $flashType = 'info';

    switch ($action) {
        case 'check_upgrade':
            $availableVersion = '3.2.1';
            $upgradeState['last_check'] = time();
            if (version_compare($availableVersion, (string)$upgradeState['current_version'], '>')) {
                $upgradeState['available_version'] = $availableVersion;
                $upgradeState['backup_ready'] = false;
                $flashMessage = sprintf(t($t, 'upgrade_available', 'Version %s is available for installation.'), $availableVersion);
                $flashType = 'success';
            } else {
                $upgradeState['available_version'] = null;
                $flashMessage = t($t, 'upgrade_latest', 'You are already on the latest version.');
                $flashType = 'success';
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

                $zip->close();
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
            header('Content-Length: ' . (string)filesize($fullPath));
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
            $upgradeState['current_version'] = $upgradeState['available_version'];
            $upgradeState['available_version'] = null;
            $upgradeState['backup_ready'] = false;
            $flashMessage = t($t, 'upgrade_complete', 'Upgrade installed successfully.');
            $flashType = 'success';
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
$backupReady = !empty($upgradeState['backup_ready']);
$lastCheckDisplay = !empty($upgradeState['last_check']) ? date('M j, Y g:i a', (int)$upgradeState['last_check']) : null;
$lastBackupDisplay = !empty($upgradeState['last_backup_at']) ? date('M j, Y g:i a', (int)$upgradeState['last_backup_at']) : null;
$backupDownloadUrl = !empty($upgradeState['last_backup_path']) ? asset_url($upgradeState['last_backup_path']) : null;
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'admin_dashboard','Admin Dashboard'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
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

  <div class="md-dashboard-grid">
    <div class="md-card md-elev-2 md-dashboard-card">
      <h2 class="md-card-title"><?=t($t,'system_upgrade','System Upgrade')?></h2>
      <div class="md-upgrade-status">
        <div><strong><?=t($t,'current_version','Current version')?>:</strong> <span class="md-status-badge success"><?=htmlspecialchars((string)$upgradeState['current_version'], ENT_QUOTES, 'UTF-8')?></span></div>
        <div><strong><?=t($t,'available_version','Available version')?>:</strong>
          <?php if ($availableVersion): ?>
            <span class="md-status-badge warning"><?=htmlspecialchars($availableVersion, ENT_QUOTES, 'UTF-8')?></span>
          <?php else: ?>
            <span class="md-status-badge success"><?=t($t,'no_update_required','Up to date')?></span>
          <?php endif; ?>
        </div>
        <div><strong><?=t($t,'backup_status','Backup status')?>:</strong>
          <span class="md-status-badge <?=$backupReady ? 'success' : 'warning'?>"><?=$backupReady ? t($t,'backup_ready','Backup ready') : t($t,'backup_required','Backup required')?></span>
        </div>
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
