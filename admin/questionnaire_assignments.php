<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin','supervisor']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$message = $_SESSION['questionnaire_assignment_flash'] ?? '';
$error = $_SESSION['questionnaire_assignment_error'] ?? '';
unset($_SESSION['questionnaire_assignment_flash'], $_SESSION['questionnaire_assignment_error']);

try {
    $staffStmt = $pdo->query("SELECT id, username, full_name, work_function FROM users WHERE role='staff' AND account_status='active' ORDER BY full_name ASC, username ASC");
    $staffMembers = $staffStmt ? $staffStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log('questionnaire_assignments staff fetch failed: ' . $e->getMessage());
    $staffMembers = [];
}

$staffById = [];
foreach ($staffMembers as $member) {
    $staffById[(int)$member['id']] = $member;
}

$selectedStaffId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selectedStaffId = (int)($_POST['staff_id'] ?? 0);
    $questionnaireIds = isset($_POST['questionnaire_ids']) ? $_POST['questionnaire_ids'] : [];
    $questionnaireIds = array_values(array_filter(array_map(static function ($value) {
        if (is_numeric($value)) {
            $intVal = (int)$value;
            if ($intVal > 0) {
                return $intVal;
            }
        }
        return null;
    }, (array)$questionnaireIds), static fn($val) => $val !== null));

    try {
        $staffStmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE id = ? AND role='staff' AND account_status='active'");
        $staffStmt->execute([$selectedStaffId]);
        $staffRecord = $staffStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $staffRecord = false;
        error_log('questionnaire_assignments staff lookup failed: ' . $e->getMessage());
    }

    if (!$staffRecord) {
        $error = t($t, 'invalid_user_selection', 'Please choose a valid user.');
        $_SESSION['questionnaire_assignment_error'] = $error;
    } else {
        try {
            $pdo->beginTransaction();
            $deleteStmt = $pdo->prepare('DELETE FROM questionnaire_assignment WHERE staff_id = ?');
            $deleteStmt->execute([$selectedStaffId]);

            if ($questionnaireIds) {
                $insertStmt = $pdo->prepare('INSERT INTO questionnaire_assignment (staff_id, questionnaire_id, assigned_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), assigned_at = CURRENT_TIMESTAMP');
                foreach ($questionnaireIds as $qid) {
                    $insertStmt->execute([$selectedStaffId, $qid, $_SESSION['user']['id']]);
                }
            }

            $pdo->commit();

            $staffDetails = null;
            try {
                $staffDetailsStmt = $pdo->prepare('SELECT id, username, full_name, email, next_assessment_date FROM users WHERE id = ?');
                $staffDetailsStmt->execute([$selectedStaffId]);
                $staffDetails = $staffDetailsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (PDOException $e) {
                error_log('questionnaire_assignments staff detail fetch failed: ' . $e->getMessage());
            }

            $assignedTitles = [];
            if ($staffDetails) {
                try {
                    $titlesStmt = $pdo->prepare('SELECT q.title FROM questionnaire_assignment qa JOIN questionnaire q ON q.id = qa.questionnaire_id WHERE qa.staff_id = ? ORDER BY q.title ASC');
                    $titlesStmt->execute([$selectedStaffId]);
                    $titles = $titlesStmt->fetchAll(PDO::FETCH_COLUMN);
                    $fallbackTitle = t($t, 'questionnaire', 'Questionnaire');
                    foreach ($titles as $title) {
                        $normalized = trim((string)$title);
                        $assignedTitles[] = $normalized !== '' ? $normalized : $fallbackTitle;
                    }
                } catch (PDOException $e) {
                    error_log('questionnaire_assignments assignment titles fetch failed: ' . $e->getMessage());
                }

                $assigner = $_SESSION['user'] ?? null;
                notify_questionnaire_assignment_update($cfg, $staffDetails, $assignedTitles, $assigner);
            }

            $_SESSION['questionnaire_assignment_flash'] = t($t, 'assignments_saved', 'Assignments updated successfully.');
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('questionnaire_assignments save failed: ' . $e->getMessage());
            $_SESSION['questionnaire_assignment_error'] = t($t, 'assignments_save_failed', 'Unable to update assignments. Please try again.');
        }
    }

    header('Location: ' . url_for('admin/questionnaire_assignments.php?staff_id=' . $selectedStaffId));
    exit;
}

if ($selectedStaffId <= 0) {
    $selectedStaffId = (int)($_GET['staff_id'] ?? ($staffMembers[0]['id'] ?? 0));
}

$selectedStaffRecord = $staffById[$selectedStaffId] ?? null;
$selectedWorkFunction = '';
if ($selectedStaffRecord) {
    $selectedWorkFunction = trim((string)($selectedStaffRecord['work_function'] ?? ''));
}
$selectedWorkFunctionLabel = $selectedWorkFunction !== '' ? work_function_label($pdo, $selectedWorkFunction) : '';
$defaultQuestionnaires = [];
$defaultAssignmentIds = [];
if ($selectedWorkFunction !== '') {
    try {
        $defaultStmt = $pdo->prepare(
            'SELECT q.id, q.title FROM questionnaire_work_function qwf '
            . 'JOIN questionnaire q ON q.id = qwf.questionnaire_id '
            . 'WHERE qwf.work_function = ? ORDER BY q.title ASC'
        );
        $defaultStmt->execute([$selectedWorkFunction]);
        $defaultQuestionnaires = $defaultStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $defaultAssignmentIds = array_map('intval', array_column($defaultQuestionnaires, 'id'));
    } catch (PDOException $e) {
        error_log('questionnaire_assignments default lookup failed: ' . $e->getMessage());
        $defaultQuestionnaires = [];
        $defaultAssignmentIds = [];
    }
}
$defaultsLabelText = '';
if ($selectedWorkFunctionLabel !== '') {
    $defaultsLabelText = sprintf(
        t($t, 'assignment_defaults_label', 'Work function: %s'),
        $selectedWorkFunctionLabel
    );
}

try {
    $questionnaireStmt = $pdo->query('SELECT id, title, description FROM questionnaire ORDER BY title ASC');
    $questionnaires = $questionnaireStmt ? $questionnaireStmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (PDOException $e) {
    error_log('questionnaire_assignments questionnaire fetch failed: ' . $e->getMessage());
    $questionnaires = [];
}

$assignedIds = [];
if ($selectedStaffId > 0) {
    try {
        $assignedStmt = $pdo->prepare('SELECT questionnaire_id FROM questionnaire_assignment WHERE staff_id = ?');
        $assignedStmt->execute([$selectedStaffId]);
        $assignedIds = array_map('intval', $assignedStmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('questionnaire_assignments fetch assignments failed: ' . $e->getMessage());
        $assignedIds = [];
    }
}

?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'assign_questionnaires','Assign Questionnaires'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style>
    .md-assignment-select {
      margin-bottom: 1rem;
    }
    .md-assignment-grid {
      display: grid;
      gap: 0.75rem;
    }
    .md-assignment-defaults {
      margin-bottom: 1rem;
    }
    .md-assignment-defaults ul {
      margin: 0.5rem 0 0 1.25rem;
      padding-left: 0;
    }
    .md-assignment-defaults li {
      margin: 0.25rem 0;
    }
    @media (min-width: 640px) {
      .md-assignment-grid {
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      }
    }
    .md-assignment-option {
      border: 1px solid var(--app-border, #d0d5dd);
      border-radius: 8px;
      padding: 0.75rem;
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      background: #fff;
    }
    .md-assignment-option--default {
      border-color: var(--app-primary, #2563eb);
      background: rgba(37, 99, 235, 0.08);
    }
    .md-assignment-option input[type="checkbox"] {
      margin-right: 0.5rem;
    }
    .md-assignment-option label {
      display: flex;
      align-items: flex-start;
      gap: 0.5rem;
      font-weight: 600;
      cursor: pointer;
    }
    .md-assignment-default-flag {
      margin-left: auto;
      font-size: 0.75rem;
      color: var(--app-primary, #2563eb);
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .md-assignment-option p {
      margin: 0;
      color: var(--app-muted, #475467);
      font-size: 0.875rem;
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'assign_questionnaires','Assign Questionnaires')?></h2>
    <?php if ($message): ?><div class="md-alert success"><?=htmlspecialchars($message, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($error): ?><div class="md-alert error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if (!$staffMembers): ?>
      <p><?=t($t,'no_active_staff','No active staff records available.')?></p>
    <?php elseif (!$questionnaires): ?>
      <p><?=t($t,'no_questionnaires_configured','No questionnaires are configured yet.')?></p>
    <?php else: ?>
      <form method="get" class="md-inline-form md-assignment-select" action="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>">
        <label for="staff_id"><?=t($t,'select_staff_member','Select staff member')?>:</label>
        <select name="staff_id" id="staff_id" onchange="this.form.submit()">
          <?php foreach ($staffMembers as $staff): ?>
            <?php
              $name = trim((string)($staff['full_name'] ?? ''));
              if ($name === '') {
                  $name = (string)($staff['username'] ?? '');
              }
            ?>
            <option value="<?=$staff['id']?>" <?=$selectedStaffId === (int)$staff['id'] ? 'selected' : ''?>><?=htmlspecialchars($name, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php if ($selectedStaffId > 0): ?>
        <?php if ($selectedWorkFunctionLabel !== '' || $defaultQuestionnaires): ?>
          <div class="md-alert info md-assignment-defaults">
            <strong><?=htmlspecialchars(t($t,'assignment_defaults_heading','Work-function defaults'), ENT_QUOTES, 'UTF-8')?></strong>
            <?php if ($defaultsLabelText !== ''): ?>
              <p><?=htmlspecialchars($defaultsLabelText, ENT_QUOTES, 'UTF-8')?></p>
            <?php endif; ?>
            <p><?=htmlspecialchars(t($t,'assignment_defaults_hint','These questionnaires are automatically available because of the staff member\'s work function. They cannot be removed here.'), ENT_QUOTES, 'UTF-8')?></p>
            <?php if ($defaultQuestionnaires): ?>
              <ul>
                <?php foreach ($defaultQuestionnaires as $defaultItem): ?>
                  <?php
                    $defaultTitle = trim((string)($defaultItem['title'] ?? ''));
                    if ($defaultTitle === '') {
                        $defaultTitle = t($t,'questionnaire','Questionnaire');
                    }
                  ?>
                  <li><?=htmlspecialchars($defaultTitle, ENT_QUOTES, 'UTF-8')?></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p><?=htmlspecialchars(t($t,'assignment_defaults_none','This work function does not have default questionnaires yet.'), ENT_QUOTES, 'UTF-8')?></p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <form method="post" action="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="staff_id" value="<?=$selectedStaffId?>">
        <p><?=t($t,'assignment_instructions','Select the questionnaires that should be available to this staff member in addition to their work-function defaults.')?></p>
        <div class="md-assignment-grid">
          <?php foreach ($questionnaires as $questionnaire): ?>
            <?php
              $qid = (int)$questionnaire['id'];
              $isDefault = in_array($qid, $defaultAssignmentIds, true);
              $optionClasses = 'md-assignment-option' . ($isDefault ? ' md-assignment-option--default' : '');
            ?>
            <div class="<?=htmlspecialchars($optionClasses, ENT_QUOTES, 'UTF-8')?>">
              <label>
                <input type="checkbox" name="questionnaire_ids[]" value="<?=$qid?>" <?=(in_array($qid, $assignedIds, true) ? 'checked' : '')?>>
                <span><?=htmlspecialchars($questionnaire['title'] ?? t($t,'questionnaire','Questionnaire'), ENT_QUOTES, 'UTF-8')?></span>
                <?php if ($isDefault): ?>
                  <span class="md-assignment-default-flag"><?=htmlspecialchars(t($t,'assignment_default_badge','Default'), ENT_QUOTES, 'UTF-8')?></span>
                <?php endif; ?>
              </label>
              <?php if (!empty($questionnaire['description'])): ?>
                <p><?=htmlspecialchars($questionnaire['description'], ENT_QUOTES, 'UTF-8')?></p>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="md-inline-actions" style="margin-top:1rem;">
          <button class="md-button md-primary" type="submit"><?=t($t,'save','Save')?></button>
        </div>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
