<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$flash = $_SESSION['system_data_flash'] ?? '';
$error = $_SESSION['system_data_error'] ?? '';
unset($_SESSION['system_data_flash'], $_SESSION['system_data_error']);

$definitions = [];
$definitionMap = [];
try {
    $stmt = $pdo->query('SELECT wf_key, label, is_active, display_order, created_at, updated_at FROM work_function_definition ORDER BY display_order ASC, label ASC');
    if ($stmt) {
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($definitions as $row) {
            $key = (string)($row['wf_key'] ?? '');
            if ($key !== '') {
                $definitionMap[$key] = $row;
            }
        }
    }
} catch (PDOException $e) {
    error_log('system_data definitions load failed: ' . $e->getMessage());
    $definitions = [];
    $definitionMap = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $updated = false;
    $errors = [];

    if (isset($_POST['save_work_functions'])) {
        $labels = $_POST['wf_label'] ?? [];
        $orders = $_POST['wf_order'] ?? [];
        $activeKeys = array_keys($_POST['wf_active'] ?? []);
        $activeLookup = array_fill_keys(array_map(static fn($key) => (string)$key, $activeKeys), true);

        try {
            $pdo->beginTransaction();
            $updateStmt = $pdo->prepare('UPDATE work_function_definition SET label = ?, is_active = ?, display_order = ?, updated_at = NOW() WHERE wf_key = ?');
            foreach ($definitionMap as $key => $row) {
                $label = trim((string)($labels[$key] ?? $row['label'] ?? ''));
                if ($label === '') {
                    $errors[] = sprintf(t($t, 'system_data_label_required', 'Label is required for %s.'), $key);
                    $label = (string)($row['label'] ?? $key);
                }
                $orderValue = isset($orders[$key]) ? (int)$orders[$key] : (int)($row['display_order'] ?? 0);
                if ($orderValue < 0) {
                    $orderValue = 0;
                }
                $isActive = isset($activeLookup[$key]) ? 1 : 0;
                $originalLabel = (string)($row['label'] ?? '');
                $originalActive = (int)($row['is_active'] ?? 1);
                $originalOrder = (int)($row['display_order'] ?? 0);
                if ($label !== $originalLabel || $isActive !== $originalActive || $orderValue !== $originalOrder) {
                    $updateStmt->execute([$label, $isActive, $orderValue, $key]);
                    $updated = true;
                }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('system_data update failed: ' . $e->getMessage());
            $errors[] = t($t, 'system_data_update_failed', 'Unable to update work functions. Please try again.');
        }

        if ($updated) {
            work_function_choices($pdo, true);
            $_SESSION['system_data_flash'] = t($t, 'system_data_updated', 'Work functions updated successfully.');
        }

        if ($errors) {
            $_SESSION['system_data_error'] = implode(' ', $errors);
        } elseif (!$updated) {
            $_SESSION['system_data_flash'] = $_SESSION['system_data_flash'] ?? t($t, 'system_data_no_changes', 'No changes were detected.');
        }

        header('Location: ' . url_for('admin/system_data.php'));
        exit;
    }

    if (isset($_POST['create_work_function'])) {
        $rawKey = trim((string)($_POST['new_wf_key'] ?? ''));
        $rawKey = strtolower(str_replace(' ', '_', $rawKey));
        $label = trim((string)($_POST['new_wf_label'] ?? ''));
        if ($rawKey === '' || !preg_match('/^[a-z0-9_]+$/', $rawKey)) {
            $errors[] = t($t, 'system_data_invalid_key', 'Provide a unique key using letters, numbers, or underscores.');
        }
        if ($label === '') {
            $errors[] = t($t, 'system_data_label_required_new', 'Provide a label for the new work function.');
        }
        if (isset($definitionMap[$rawKey])) {
            $errors[] = t($t, 'system_data_duplicate_key', 'That work function key already exists.');
        }
        if ($errors) {
            $_SESSION['system_data_error'] = implode(' ', $errors);
        } else {
            try {
                $orderStmt = $pdo->query('SELECT MAX(display_order) AS max_order FROM work_function_definition');
                $maxOrder = 0;
                if ($orderStmt) {
                    $maxOrderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);
                    $maxOrder = (int)($maxOrderRow['max_order'] ?? 0);
                }
                $insert = $pdo->prepare('INSERT INTO work_function_definition (wf_key, label, is_active, display_order) VALUES (?, ?, 1, ?)');
                $insert->execute([$rawKey, $label, $maxOrder + 1]);
                work_function_choices($pdo, true);
                $_SESSION['system_data_flash'] = t($t, 'system_data_created', 'Work function added successfully.');
            } catch (PDOException $e) {
                error_log('system_data create failed: ' . $e->getMessage());
                $_SESSION['system_data_error'] = t($t, 'system_data_create_failed', 'Unable to add the work function. Please try again.');
            }
        }
        header('Location: ' . url_for('admin/system_data.php'));
        exit;
    }
}

$definitions = [];
$definitionMap = [];
try {
    $stmt = $pdo->query('SELECT wf_key, label, is_active, display_order, created_at, updated_at FROM work_function_definition ORDER BY display_order ASC, label ASC');
    if ($stmt) {
        $definitions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($definitions as $row) {
            $definitionMap[(string)($row['wf_key'] ?? '')] = $row;
        }
    }
} catch (PDOException $e) {
    error_log('system_data definitions reload failed: ' . $e->getMessage());
    $definitions = [];
    $definitionMap = [];
}

$staffCounts = [];
try {
    $staffStmt = $pdo->query('SELECT work_function, COUNT(*) AS total FROM users WHERE work_function IS NOT NULL AND work_function <> "" GROUP BY work_function');
    if ($staffStmt) {
        foreach ($staffStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['work_function'] ?? '');
            if ($key !== '') {
                $staffCounts[$key] = (int)($row['total'] ?? 0);
            }
        }
    }
} catch (PDOException $e) {
    error_log('system_data staff count failed: ' . $e->getMessage());
}

$questionnaireCounts = [];
try {
    $qwfStmt = $pdo->query('SELECT work_function, COUNT(*) AS total FROM questionnaire_work_function GROUP BY work_function');
    if ($qwfStmt) {
        foreach ($qwfStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string)($row['work_function'] ?? '');
            if ($key !== '') {
                $questionnaireCounts[$key] = (int)($row['total'] ?? 0);
            }
        }
    }
} catch (PDOException $e) {
    error_log('system_data questionnaire count failed: ' . $e->getMessage());
}

$pageHelpKey = 'admin.system_data';
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'system_data','System Data'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
  <style>
    .md-system-table {
      width: 100%;
      border-collapse: collapse;
    }
    .md-system-table th,
    .md-system-table td {
      border: 1px solid var(--app-border, #d0d5dd);
      padding: 0.65rem 0.75rem;
      text-align: left;
    }
    .md-system-table th {
      background: var(--app-surface-alt, rgba(229, 231, 235, 0.35));
      font-weight: 600;
    }
    .md-system-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 1rem;
      margin: 1.5rem 0;
    }
    .md-system-add form {
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      align-items: flex-end;
    }
    .md-system-add .md-field {
      margin: 0;
      min-width: 220px;
    }
    .md-system-footnote {
      margin-top: 1rem;
      font-size: 0.85rem;
      color: var(--app-muted, #475467);
    }
  </style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'system_data','System Data')?></h2>
    <?php if ($flash): ?><div class="md-alert success"><?=htmlspecialchars($flash, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($error): ?><div class="md-alert error"><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>

    <form method="post" action="<?=htmlspecialchars(url_for('admin/system_data.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="save_work_functions" value="1">
      <table class="md-system-table">
        <thead>
          <tr>
            <th><?=t($t,'work_function_key','Key')?></th>
            <th><?=t($t,'label','Label')?></th>
            <th><?=t($t,'active','Active')?></th>
            <th><?=t($t,'display_order','Order')?></th>
            <th><?=t($t,'usage','Usage')?></th>
            <th><?=t($t,'updated','Updated')?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$definitions): ?>
            <tr>
              <td colspan="6" style="text-align:center; padding:1rem;">
                <?=t($t,'system_data_empty','No work functions have been configured yet.')?>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($definitions as $row): ?>
              <?php $key = (string)($row['wf_key'] ?? ''); ?>
              <tr>
                <td><code><?=htmlspecialchars($key, ENT_QUOTES, 'UTF-8')?></code></td>
                <td>
                  <input type="text" name="wf_label[<?=$key?>]" value="<?=htmlspecialchars($row['label'] ?? '', ENT_QUOTES, 'UTF-8')?>" required style="width:100%;">
                </td>
                <td style="text-align:center;">
                  <input type="checkbox" name="wf_active[<?=$key?>]" value="1" <?=!empty($row['is_active'])?'checked':''?>>
                </td>
                <td style="width:90px;">
                  <input type="number" name="wf_order[<?=$key?>]" value="<?=htmlspecialchars((string)($row['display_order'] ?? 0), ENT_QUOTES, 'UTF-8')?>" style="width:100%;">
                </td>
                <td>
                  <?php $staffTotal = $staffCounts[$key] ?? 0; ?>
                  <?php $questionnaireTotal = $questionnaireCounts[$key] ?? 0; ?>
                  <?php $usageText = str_replace(['{staff}','{questionnaires}'], [(string)$staffTotal, (string)$questionnaireTotal], t($t,'system_data_usage_summary','Staff: {staff}, Questionnaires: {questionnaires}')); ?>
                  <small><?=htmlspecialchars($usageText, ENT_QUOTES, 'UTF-8')?></small>
                </td>
                <td>
                  <small><?=htmlspecialchars($row['updated_at'] ?? $row['created_at'] ?? '', ENT_QUOTES, 'UTF-8')?></small>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="md-inline-actions" style="margin-top:1rem;">
        <button class="md-button md-primary" type="submit"><?=t($t,'save_changes','Save Changes')?></button>
      </div>
    </form>

    <div class="md-system-actions">
      <div class="md-system-add">
        <h3><?=t($t,'system_data_add_heading','Add Work Function')?></h3>
        <form method="post" action="<?=htmlspecialchars(url_for('admin/system_data.php'), ENT_QUOTES, 'UTF-8')?>">
          <input type="hidden" name="csrf" value="<?=csrf_token()?>">
          <input type="hidden" name="create_work_function" value="1">
          <label class="md-field">
            <span><?=t($t,'work_function_key','Key')?></span>
            <input type="text" name="new_wf_key" required placeholder="e.g. analytics_team">
          </label>
          <label class="md-field">
            <span><?=t($t,'label','Label')?></span>
            <input type="text" name="new_wf_label" required placeholder="<?=htmlspecialchars(t($t,'work_function','Work Function / Cadre'), ENT_QUOTES, 'UTF-8')?>">
          </label>
          <button class="md-button md-primary" type="submit"><?=t($t,'add','Add')?></button>
        </form>
        <p class="md-system-footnote"><?=t($t,'system_data_add_help','Keys use lowercase letters, numbers, or underscores and appear in integrations. Labels are shown to users.')?></p>
      </div>
    </div>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
