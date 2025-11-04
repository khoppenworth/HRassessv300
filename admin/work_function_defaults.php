<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$workFunctionChoices = work_function_choices($pdo);
$defaultWorkFunctions = default_work_function_definitions();
$questionnaires = [];
$questionnaireMap = [];
try {
    $stmt = $pdo->query("SELECT id, title, description FROM questionnaire WHERE status='published' ORDER BY title ASC");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = isset($row['id']) ? (int)$row['id'] : 0;
            if ($qid <= 0) {
                continue;
            }
            $questionnaires[] = $row;
            $questionnaireMap[$qid] = $row;
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults questionnaire fetch failed: ' . $e->getMessage());
    $questionnaires = [];
    $questionnaireMap = [];
}
$existingAssignments = [];
try {
    $defaultsStmt = $pdo->query('SELECT questionnaire_id, work_function FROM questionnaire_work_function');
    if ($defaultsStmt) {
        foreach ($defaultsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qid = isset($row['questionnaire_id']) ? (int)$row['questionnaire_id'] : 0;
            $wf = trim((string)($row['work_function'] ?? ''));
            if ($qid > 0 && $wf !== '') {
                $existingAssignments[$wf][] = $qid;
            }
        }
    }
} catch (PDOException $e) {
    error_log('work_function_defaults default fetch failed: ' . $e->getMessage());
    $existingAssignments = [];
}
$workFunctionKeys = array_unique(array_merge(
    array_keys($defaultWorkFunctions),
    array_keys($workFunctionChoices),
    array_keys($existingAssignments)
));
usort($workFunctionKeys, static function ($a, $b) use ($pdo) {
    return strcasecmp(work_function_label($pdo, (string)$a), work_function_label($pdo, (string)$b));
});
$assignmentsByWorkFunction = [];
foreach ($workFunctionKeys as $wf) {
    $assignmentsByWorkFunction[$wf] = array_values(array_unique($existingAssignments[$wf] ?? []));
}
$msg = $_SESSION['work_function_defaults_flash'] ?? '';
if ($msg !== '') {
    unset($_SESSION['work_function_defaults_flash']);
}
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $input = $_POST['assignments'] ?? [];
    if (!is_array($input)) {
        $input = [];
    }
    $normalized = [];
    foreach ($workFunctionKeys as $wf) {
        $selection = $input[$wf] ?? [];
        if (!is_array($selection)) {
            $selection = [];
        }
        $valid = [];
        foreach ($selection as $value) {
            $qid = (int)$value;
            if ($qid <= 0 || !isset($questionnaireMap[$qid])) {
                continue;
            }
            $valid[$qid] = $qid;
        }
        $normalized[$wf] = array_values($valid);
    }
    $assignmentsByWorkFunction = $normalized;
    if ($errors === []) {
        try {
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM questionnaire_work_function');
            if ($normalized !== []) {
                $insert = $pdo->prepare('INSERT INTO questionnaire_work_function (questionnaire_id, work_function) VALUES (?, ?)');
                foreach ($normalized as $wf => $ids) {
                    foreach ($ids as $qid) {
                        $insert->execute([$qid, $wf]);
                    }
                }
            }
            $pdo->commit();
            $_SESSION['work_function_defaults_flash'] = t($t, 'work_function_defaults_saved', 'Default questionnaire assignments updated.');
            header('Location: ' . url_for('admin/work_function_defaults.php'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('work_function_defaults save failed: ' . $e->getMessage());
            $errors[] = t($t, 'work_function_defaults_save_failed', 'Unable to save work function defaults. Please try again.');
        }
    }
}
$selectSize = count($questionnaires) > 0 ? max(4, min(count($questionnaires), 10)) : 4;
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php $drawerKey = 'admin.work_function_defaults'; ?>
<?php include __DIR__ . '/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=htmlspecialchars(t($t, 'work_function_defaults_title', 'Work Function Defaults'), ENT_QUOTES, 'UTF-8')?></h2>
    <p class="md-hint md-work-function-hint"><?=htmlspecialchars(t($t, 'work_function_defaults_hint', 'Choose the questionnaires that should be provided automatically to staff members based on their work function or cadre.'), ENT_QUOTES, 'UTF-8')?></p>
    <?php if ($msg !== ''): ?>
      <div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="md-alert error">
        <?php foreach ($errors as $error): ?>
          <p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" action="<?=htmlspecialchars(url_for('admin/work_function_defaults.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <div class="md-work-function-grid">
        <?php if ($workFunctionKeys === []): ?>
          <p class="md-hint"><?=htmlspecialchars(t($t, 'work_function_defaults_none', 'No work functions are available yet. Staff members can continue to receive questionnaires assigned directly to them.'), ENT_QUOTES, 'UTF-8')?></p>
        <?php endif; ?>
        <?php foreach ($workFunctionKeys as $wf): ?>
          <?php $label = work_function_label($pdo, $wf); ?>
          <div class="md-work-function-card" data-work-function-block>
            <div class="md-work-function-heading">
              <h3><?=htmlspecialchars($label, ENT_QUOTES, 'UTF-8')?></h3>
              <p><?=htmlspecialchars(t($t, 'work_function_defaults_card_hint', 'Staff in this work function receive the selected questionnaires by default.'), ENT_QUOTES, 'UTF-8')?></p>
            </div>
            <label class="md-field md-field--compact">
              <span><?=htmlspecialchars(t($t, 'questionnaires', 'Questionnaires'), ENT_QUOTES, 'UTF-8')?></span>
              <select
                class="md-work-function-select"
                name="assignments[<?=htmlspecialchars($wf, ENT_QUOTES, 'UTF-8')?>][]"
                data-work-function-select
                data-work-function="<?=htmlspecialchars($wf, ENT_QUOTES, 'UTF-8')?>"
                multiple
                size="<?=$selectSize?>"
                <?php if (!$questionnaires): ?>disabled<?php endif; ?>
              >
                <?php foreach ($questionnaires as $questionnaire): ?>
                  <?php
                    $qid = (int)$questionnaire['id'];
                    $title = trim((string)($questionnaire['title'] ?? ''));
                    $description = trim((string)($questionnaire['description'] ?? ''));
                    $display = $title !== '' ? $title : t($t, 'untitled_questionnaire', 'Untitled questionnaire');
                    if ($description !== '') {
                        $display .= ' — ' . $description;
                    }
                    $selected = in_array($qid, $assignmentsByWorkFunction[$wf] ?? [], true);
                  ?>
                  <option value="<?=$qid?>" <?=$selected ? 'selected' : ''?>><?=htmlspecialchars($display, ENT_QUOTES, 'UTF-8')?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="md-work-function-actions">
              <button type="button" class="md-button md-outline" data-select-all><?=htmlspecialchars(t($t, 'select_all', 'Select All'), ENT_QUOTES, 'UTF-8')?></button>
              <button type="button" class="md-button md-outline" data-clear-all><?=htmlspecialchars(t($t, 'clear_all', 'Clear All'), ENT_QUOTES, 'UTF-8')?></button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$questionnaires): ?>
        <p class="md-hint md-work-function-hint"><?=htmlspecialchars(t($t, 'no_questionnaires_configured', 'No questionnaires are configured yet.'), ENT_QUOTES, 'UTF-8')?></p>
      <?php endif; ?>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2" type="submit"><?=htmlspecialchars(t($t, 'save', 'Save Changes'), ENT_QUOTES, 'UTF-8')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__ . '/../templates/footer.php'; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function () {
  const blocks = document.querySelectorAll('[data-work-function-block]');
  blocks.forEach((block) => {
    const select = block.querySelector('[data-work-function-select]');
    if (!select) {
      return;
    }
    const selectAllBtn = block.querySelector('[data-select-all]');
    const clearBtn = block.querySelector('[data-clear-all]');
    if (selectAllBtn) {
      selectAllBtn.addEventListener('click', () => {
        Array.from(select.options).forEach((option) => {
          option.selected = !option.disabled;
        });
        select.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        Array.from(select.options).forEach((option) => {
          option.selected = false;
        });
        select.dispatchEvent(new Event('change', { bubbles: true }));
      });
    }
  });
})();
</script>
</body>
</html>
