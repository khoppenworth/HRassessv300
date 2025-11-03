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
                    $titlesStmt = $pdo->prepare("SELECT q.title FROM questionnaire_assignment qa JOIN questionnaire q ON q.id = qa.questionnaire_id WHERE qa.staff_id = ? AND q.status='published' ORDER BY q.title ASC");
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
try {
    $questionnaireStmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
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

$pageHelpKey = 'team.assignments';
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
    .md-assignment-summary {
      margin-bottom: 1rem;
      padding: 0.75rem 1rem;
      border: 1px solid var(--app-border, #d0d5dd);
      border-radius: 8px;
      background: rgba(37, 99, 235, 0.06);
      color: var(--app-text-primary, #1f2937);
    }
    .md-assignment-summary strong {
      display: inline-block;
      margin-right: 0.35rem;
    }
    .md-assignment-multiselect {
      margin: 1rem 0;
      display: block;
    }
    .md-assignment-multiselect .md-field-label {
      display: block;
      margin-bottom: 0.4rem;
      font-weight: 600;
    }
    .md-assignment-multiselect select {
      width: 100%;
      min-height: 260px;
      padding: 0.65rem;
      border-radius: 8px;
      border: 1px solid var(--app-border, #d0d5dd);
      background: var(--app-surface, #ffffff);
      font-size: 0.98rem;
      line-height: 1.35;
    }
    .md-assignment-multiselect small {
      display: block;
      margin-top: 0.35rem;
      color: var(--app-muted, #475467);
      font-size: 0.8rem;
    }
    .md-assignment-tools {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: flex-end;
      margin: 1rem 0 0.5rem;
    }
    .md-assignment-tools .md-field {
      flex: 1 1 240px;
      min-width: 200px;
      margin: 0;
    }
    .md-assignment-tool-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }
    .md-assignment-count {
      margin: 0;
      color: var(--app-muted, #475467);
      font-size: 0.85rem;
    }
    .md-assignment-selected {
      margin: 0.5rem 0 0;
      padding: 0.75rem;
      border-radius: 8px;
      border: 1px solid var(--app-border, #d0d5dd);
      background: var(--app-surface-alt, rgba(229, 231, 235, 0.5));
      font-size: 0.9rem;
    }
    .md-assignment-selected strong {
      display: block;
      margin-bottom: 0.35rem;
      color: var(--app-text-primary, #1f2937);
    }
    .md-assignment-selected ul {
      margin: 0;
      padding-left: 1.1rem;
      columns: 2;
      column-gap: 1.25rem;
      list-style: disc;
    }
    @media (max-width: 720px) {
      .md-assignment-selected ul {
        columns: 1;
      }
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
      <?php if ($selectedStaffRecord): ?>
        <div class="md-assignment-summary">
          <p><strong><?=t($t,'selected_staff','Selected staff')?>:</strong> <?=htmlspecialchars(($selectedStaffRecord['full_name'] ?? $selectedStaffRecord['username'] ?? ''), ENT_QUOTES, 'UTF-8')?></p>
          <?php $workFunction = trim((string)($selectedStaffRecord['work_function'] ?? '')); ?>
          <?php if ($workFunction !== ''): ?>
            <p><strong><?=t($t,'current_work_function','Current work function:')?></strong> <?=htmlspecialchars(work_function_label($pdo, $workFunction) ?: $workFunction, ENT_QUOTES, 'UTF-8')?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <form method="post" action="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="staff_id" value="<?=$selectedStaffId?>">
        <p><?=t($t,'assignment_instructions','Choose the questionnaires that should be available to this staff member.')?></p>
        <div class="md-assignment-tools">
          <label class="md-field md-assignment-filter">
            <span><?=t($t,'filter_questionnaires','Filter questionnaires')?></span>
            <input
              type="search"
              name="assignment_filter"
              placeholder="<?=htmlspecialchars(t($t,'filter_questionnaires_placeholder','Type to narrow the list…'), ENT_QUOTES, 'UTF-8')?>"
              data-assignment-filter
            >
          </label>
          <div class="md-assignment-tool-buttons">
            <button class="md-button md-outline" type="button" data-assignment-select-all>
              <?=t($t,'select_all','Select All')?>
            </button>
            <button class="md-button md-outline" type="button" data-assignment-clear-all>
              <?=t($t,'clear_all','Clear All')?>
            </button>
          </div>
          <p
            class="md-assignment-count"
            data-assignment-count
            data-singular="<?=htmlspecialchars(t($t,'single_questionnaire_selected','1 questionnaire selected'), ENT_QUOTES, 'UTF-8')?>"
            data-plural-template="<?=htmlspecialchars(t($t,'multiple_questionnaires_selected','{count} questionnaires selected'), ENT_QUOTES, 'UTF-8')?>"
          ></p>
        </div>
        <label class="md-assignment-multiselect">
          <span class="md-field-label"><?=t($t,'available_questionnaires','Available questionnaires')?></span>
          <?php $selectSize = max(8, min(15, count($questionnaires))); ?>
          <select name="questionnaire_ids[]" multiple size="<?=$selectSize?>" data-assignment-select>
            <?php foreach ($questionnaires as $questionnaire): ?>
              <?php $qid = (int)$questionnaire['id']; ?>
              <option value="<?=$qid?>" <?=(in_array($qid, $assignedIds, true) ? 'selected' : '')?>><?=htmlspecialchars($questionnaire['title'] ?? t($t,'questionnaire','Questionnaire'), ENT_QUOTES, 'UTF-8')?><?php if (!empty($questionnaire['description'])): ?> — <?=htmlspecialchars($questionnaire['description'], ENT_QUOTES, 'UTF-8')?><?php endif; ?></option>
            <?php endforeach; ?>
          </select>
          <small><?=t($t,'assignment_multiselect_hint','Hold Ctrl (Windows) or Command (macOS) to select more than one item.')?></small>
        </label>
        <div class="md-assignment-selected" data-assignment-selected hidden>
          <strong><?=t($t,'currently_assigned','Currently assigned questionnaires:')?></strong>
          <ul data-assignment-selected-list></ul>
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
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  (function () {
    const selectEl = document.querySelector('[data-assignment-select]');
    if (!selectEl) {
      return;
    }

    const filterInput = document.querySelector('[data-assignment-filter]');
    const selectAllBtn = document.querySelector('[data-assignment-select-all]');
    const clearAllBtn = document.querySelector('[data-assignment-clear-all]');
    const countLabel = document.querySelector('[data-assignment-count]');
    const selectedContainer = document.querySelector('[data-assignment-selected]');
    const selectedList = document.querySelector('[data-assignment-selected-list]');

    const normalizeText = (text) => text ? text.toLowerCase().trim() : '';

    const updateSelectionSummary = () => {
      const selectedOptions = Array.from(selectEl.selectedOptions || []);
      const count = selectedOptions.length;
      if (countLabel) {
        const singular = countLabel.getAttribute('data-singular') || '';
        const pluralTemplate = countLabel.getAttribute('data-plural-template') || '';
        if (count === 1 && singular) {
          countLabel.textContent = singular;
        } else if (count > 1 && pluralTemplate) {
          countLabel.textContent = pluralTemplate.replace('{count}', String(count));
        } else if (count === 0) {
          countLabel.textContent = '';
        } else {
          const label = count === 1 ? '<?=t($t,'questionnaire','Questionnaire')?>' : '<?=t($t,'questionnaires_selected','questionnaires selected')?>';
          countLabel.textContent = count === 1 ? `1 ${label}` : `${count} ${label}`;
        }
      }
      if (!selectedContainer || !selectedList) {
        return;
      }
      selectedList.innerHTML = '';
      if (!count) {
        selectedContainer.hidden = true;
        return;
      }
      selectedOptions.forEach((option) => {
        const li = document.createElement('li');
        li.textContent = option.textContent || option.label || option.value;
        selectedList.appendChild(li);
      });
      selectedContainer.hidden = false;
    };

    const applyFilter = (term) => {
      const normalized = normalizeText(term);
      Array.from(selectEl.options).forEach((option) => {
        const label = normalizeText(option.textContent || option.label || '');
        const matches = !normalized || label.includes(normalized);
        option.hidden = !matches && !option.selected;
      });
    };

    selectEl.addEventListener('change', updateSelectionSummary);
    selectEl.addEventListener('keyup', updateSelectionSummary);

    if (filterInput) {
      filterInput.addEventListener('input', (event) => {
        applyFilter(event.target.value);
      });
    }

    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', () => {
        Array.from(selectEl.options).forEach((option) => {
          if (!option.disabled) {
            option.selected = true;
          }
        });
        updateSelectionSummary();
      });
    }

    if (clearAllBtn) {
      clearAllBtn.addEventListener('click', () => {
        Array.from(selectEl.options).forEach((option) => {
          option.selected = false;
        });
        updateSelectionSummary();
      });
    }

    updateSelectionSummary();
  })();
</script>
</body>
</html>
