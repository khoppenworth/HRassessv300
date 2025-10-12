<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$msg = $_SESSION['admin_users_flash'] ?? '';
if ($msg !== '') {
    unset($_SESSION['admin_users_flash']);
}
$roleOptions = get_user_roles($pdo);
$roleMap = [];
foreach ($roleOptions as $roleRow) {
    $key = (string)$roleRow['role_key'];
    $roleMap[$key] = $roleRow;
}
$defaultRoleKey = 'staff';
if (!isset($roleMap[$defaultRoleKey]) && $roleOptions) {
    $first = reset($roleOptions);
    if ($first) {
        $defaultRoleKey = (string)$first['role_key'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['create'])) {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $workFunction = $_POST['work_function'] ?? 'general_service';
        $accountStatus = $_POST['account_status'] ?? 'active';
        $nextAssessment = trim($_POST['next_assessment_date'] ?? '');
        if (!in_array($accountStatus, ['active','pending','disabled'], true)) {
            $accountStatus = 'active';
        }
        if ($nextAssessment !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $nextAssessment);
            if (!$dt) {
                $msg = t($t, 'invalid_date', 'Please provide a valid next assessment date.');
            } else {
                $nextAssessment = $dt->format('Y-m-d');
            }
        } else {
            $nextAssessment = null;
        }

        if ($msg === '') {
            if ($username === '' || $password === '') {
                $msg = t($t, 'admin_user_required', 'Username and password are required.');
            } elseif (!isset($roleMap[$role])) {
                $msg = t($t, 'invalid_role', 'Invalid role selection.');
            } else {
                if (!in_array($workFunction, WORK_FUNCTIONS, true)) { $workFunction = 'general_service'; }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                try {
                    $stm = $pdo->prepare("INSERT INTO users (username,password,role,full_name,email,work_function,account_status,next_assessment_date) VALUES (?,?,?,?,?,?,?,?)");
                    $stm->execute([
                        $username,
                        $hash,
                        $role,
                        $_POST['full_name'] ?? null,
                        $_POST['email'] ?? null,
                        $workFunction,
                        $accountStatus,
                        $nextAssessment
                    ]);
                    $msg = t($t, 'user_created', 'User created successfully.');
                } catch (PDOException $e) {
                    if ((int)$e->getCode() === 23000) {
                        $msg = t($t, 'username_exists', 'A user with that username already exists.');
                    } else {
                        error_log('Admin user create failed: ' . $e->getMessage());
                        $msg = t($t, 'user_create_failed', 'Unable to create user. Please try again.');
                    }
                }
            }
        }
    }

    if (isset($_POST['reset'])) {
        $id = (int)($_POST['id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $workFunction = $_POST['work_function'] ?? 'general_service';
        $accountStatus = $_POST['account_status'] ?? 'active';
        $nextAssessment = trim($_POST['next_assessment_date'] ?? '');
        if (!in_array($accountStatus, ['active','pending','disabled'], true)) {
            $accountStatus = 'active';
        }
        if ($nextAssessment !== '') {
            $dt = DateTime::createFromFormat('Y-m-d', $nextAssessment);
            if (!$dt) {
                $msg = t($t, 'invalid_date', 'Please provide a valid next assessment date.');
            } else {
                $nextAssessment = $dt->format('Y-m-d');
            }
        } else {
            $nextAssessment = null;
        }

        if ($msg === '') {
            if ($id <= 0) {
                $msg = t($t, 'admin_reset_required', 'User selection is required.');
            } elseif (!isset($roleMap[$role])) {
                $msg = t($t, 'invalid_role', 'Invalid role selection.');
            } else {
                if (!in_array($workFunction, WORK_FUNCTIONS, true)) { $workFunction = 'general_service'; }
                $fields = ['role = ?', 'work_function = ?', 'account_status = ?', 'next_assessment_date = ?'];
                $params = [$role, $workFunction, $accountStatus, $nextAssessment, $id];
                $profileReset = '';
                if (is_string($newPassword) && trim($newPassword) !== '') {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    array_unshift($params, $hash);
                    $fields = array_merge(['password = ?'], $fields);
                    $profileReset = ', profile_completed = 0';
                }
                $sql = 'UPDATE users SET ' . implode(', ', $fields) . $profileReset . ' WHERE id = ?';
                try {
                    $stm = $pdo->prepare($sql);
                    $stm->execute($params);
                    $msg = t($t, 'user_updated', 'User updated successfully.');
                } catch (PDOException $e) {
                    error_log('Admin user update failed: ' . $e->getMessage());
                    $msg = t($t, 'user_update_failed', 'Unable to update user. Please try again.');
                }
            }
        }
    }

    if (isset($_POST['delete'])) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stm = $pdo->prepare('DELETE FROM users WHERE id=?');
            $stm->execute([$id]);
            $_SESSION['admin_users_flash'] = t($t, 'user_deleted', 'User deleted successfully.');
            header('Location: ' . url_for('admin/users.php'));
            exit;
        }
    }
}
$rows = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
$roleLabels = [];
foreach ($roleOptions as $option) {
    $label = (string)($option['label'] ?? $option['role_key']);
    $roleLabels[(string)$option['role_key']] = $label;
}
$statusLabels = [
    'active' => t($t,'status_active','Active'),
    'pending' => t($t,'status_pending','Pending approval'),
    'disabled' => t($t,'status_disabled','Disabled'),
];
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head><meta charset="utf-8"><title><?=htmlspecialchars(t($t,'manage_users','Manage Users'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
<style>
  .md-user-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
    margin-bottom: 1rem;
  }
  .md-user-search {
    max-width: 320px;
  }
  .md-user-card--hidden {
    display: none !important;
  }
  .md-user-grid[data-has-results="false"]::before {
    content: attr(data-empty-message);
    display: block;
    padding: 1rem;
    color: var(--app-muted);
    font-style: italic;
  }
</style>
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
<?php if ($msg): ?><div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<div class="md-card md-elev-2"><h2 class="md-card-title"><?=t($t,'create_user','Create User')?></h2>
<form method="post" class="md-form-grid" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<label class="md-field"><span><?=t($t,'username','Username')?></span><input name="username" required></label>
<label class="md-field"><span><?=t($t,'password','Password')?></span><input name="password" type="password" required></label>
  <label class="md-field"><span><?=t($t,'role','Role')?></span>
    <select name="role">
      <?php foreach ($roleOptions as $option): ?>
        <?php $optionKey = (string)$option['role_key']; ?>
        <option value="<?=htmlspecialchars($optionKey, ENT_QUOTES, 'UTF-8')?>" <?=$optionKey===$defaultRoleKey?'selected':''?>><?=htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8')?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="md-field"><span><?=t($t,'account_status','Account Status')?></span>
    <select name="account_status">
      <option value="active"><?=t($t,'status_active','Active')?></option>
      <option value="pending"><?=t($t,'status_pending','Pending approval')?></option>
      <option value="disabled"><?=t($t,'status_disabled','Disabled')?></option>
    </select>
  </label>
<label class="md-field"><span><?=t($t,'full_name','Full Name')?></span><input name="full_name"></label>
<label class="md-field"><span><?=t($t,'email','Email')?></span><input name="email"></label>
<label class="md-field"><span><?=t($t,'work_function','Work Function / Cadre')?></span>
  <select name="work_function">
    <?php foreach (WORK_FUNCTIONS as $function): ?>
      <option value="<?=$function?>"><?=htmlspecialchars(WORK_FUNCTION_LABELS[$function] ?? $function)?></option>
    <?php endforeach; ?>
  </select>
</label>
<label class="md-field"><span><?=t($t,'next_assessment','Next Assessment Date')?></span><input type="date" name="next_assessment_date"></label>
<button name="create" class="md-button md-primary md-elev-2 md-button--wide"><?=t($t,'create','Create')?></button>
</form></div>

<div class="md-card md-elev-2"><h2 class="md-card-title"><?=t($t,'manage_users','Manage Users')?></h2>
  <?php if (!$rows): ?>
    <p class="md-empty-state"><?=t($t,'no_users_found','No user accounts were found. Create a new account to get started.')?></p>
  <?php else: ?>
    <div class="md-user-controls">
      <label class="md-field md-user-search">
        <span><?=t($t,'search_last_name','Search by last name')?></span>
        <input type="search" placeholder="<?=htmlspecialchars(t($t,'search_last_name_placeholder','Start typing a last name'), ENT_QUOTES, 'UTF-8')?>" data-user-search>
      </label>
    </div>
    <div class="md-user-grid" data-has-results="true" data-empty-message="<?=htmlspecialchars(t($t,'no_matching_users','No users match your search.'), ENT_QUOTES, 'UTF-8')?>">
      <?php foreach ($rows as $r): ?>
        <?php
          $statusKey = $r['account_status'] ?? 'active';
          $statusLabel = $statusLabels[$statusKey] ?? $statusKey;
          $statusSlug = preg_replace('/[^a-z0-9_-]/i', '', (string)$statusKey);
          if ($statusSlug === '') {
              $statusSlug = 'unknown';
          }
          $statusClass = 'status-' . $statusSlug;
          $fullName = trim((string)($r['full_name'] ?? ''));
          $displayName = $fullName !== '' ? $fullName : $r['username'];
          $nameParts = preg_split('/\s+/u', trim($displayName));
          $initials = '';
          if ($nameParts && $nameParts[0] !== '') {
              $initials .= mb_substr($nameParts[0], 0, 1, 'UTF-8');
          }
          if ($nameParts && count($nameParts) > 1) {
              $initials .= mb_substr($nameParts[count($nameParts) - 1], 0, 1, 'UTF-8');
          }
          if ($initials === '') {
              $initials = mb_substr((string)$r['username'], 0, 2, 'UTF-8');
          }
          $initials = mb_strtoupper(mb_substr($initials, 0, 2, 'UTF-8'), 'UTF-8');
          $email = trim((string)($r['email'] ?? ''));
          $workFunctionLabel = WORK_FUNCTION_LABELS[$r['work_function']] ?? $r['work_function'];
          $nextAssessment = $r['next_assessment_date'] ?? '';
          $nextAssessmentDisplay = '—';
          if ($nextAssessment !== '') {
              $ts = strtotime($nextAssessment);
              $nextAssessmentDisplay = $ts ? date('M j, Y', $ts) : $nextAssessment;
          }
          $createdAt = $r['created_at'] ?? '';
          $createdDisplay = '—';
          if ($createdAt !== '') {
              $ts = strtotime((string)$createdAt);
              $createdDisplay = $ts ? date('M j, Y', $ts) : $createdAt;
          }
          $roleKey = $r['role'] ?? 'staff';
          $roleLabel = $roleLabels[$roleKey] ?? $roleKey;
          $lastName = '';
          if ($fullName !== '') {
              $lastNameParts = preg_split('/\s+/u', trim($fullName));
              if ($lastNameParts && count($lastNameParts) > 0) {
                  $lastName = (string)end($lastNameParts);
              }
          }
          if ($lastName === '') {
              $lastName = (string)$r['username'];
          }
          $searchLast = mb_strtolower($lastName, 'UTF-8');
          $searchFull = mb_strtolower($displayName, 'UTF-8');
          $searchUser = mb_strtolower((string)$r['username'], 'UTF-8');
        ?>
        <article class="md-user-card" data-last-name="<?=htmlspecialchars($searchLast, ENT_QUOTES, 'UTF-8')?>" data-full-name="<?=htmlspecialchars($searchFull, ENT_QUOTES, 'UTF-8')?>" data-username="<?=htmlspecialchars($searchUser, ENT_QUOTES, 'UTF-8')?>">
          <header class="md-user-card__header">
            <div class="md-user-avatar" aria-hidden="true"><?=htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')?></div>
            <div class="md-user-card__heading">
              <h3><?=htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8')?></h3>
              <p>@<?=htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8')?></p>
            </div>
            <span class="md-user-chip <?=$statusClass?>"><?=htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8')?></span>
          </header>
          <dl class="md-user-meta">
            <div>
              <dt><?=t($t,'role','Role')?></dt>
              <dd><?=htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8')?></dd>
            </div>
            <div>
              <dt><?=t($t,'work_function','Work Function / Cadre')?></dt>
              <dd><?=htmlspecialchars($workFunctionLabel ?? '', ENT_QUOTES, 'UTF-8')?></dd>
            </div>
            <div>
              <dt><?=t($t,'email','Email')?></dt>
              <dd><?= $email !== '' ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '—' ?></dd>
            </div>
            <div>
              <dt><?=t($t,'next_assessment','Next Assessment')?></dt>
              <dd><?=htmlspecialchars($nextAssessmentDisplay, ENT_QUOTES, 'UTF-8')?></dd>
            </div>
            <div>
              <dt><?=t($t,'created','Created')?></dt>
              <dd><?=htmlspecialchars($createdDisplay, ENT_QUOTES, 'UTF-8')?></dd>
            </div>
          </dl>
          <div class="md-user-card__footer">
            <form method="post" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" class="md-user-update-form">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <div class="md-user-form-grid">
                <label class="md-field md-field--compact">
                  <span><?=t($t,'new_password_reset','New Password')?></span>
                  <input name="new_password" type="password" autocomplete="new-password" placeholder="<?=htmlspecialchars(t($t,'leave_blank_to_keep','Leave blank to keep current password'), ENT_QUOTES, 'UTF-8')?>">
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'role','Role')?></span>
                  <select name="role">
                    <?php foreach ($roleOptions as $option): ?>
                      <option value="<?=htmlspecialchars($option['role_key'], ENT_QUOTES, 'UTF-8')?>" <?=$roleKey===$option['role_key']?'selected':''?>><?=htmlspecialchars($option['label'], ENT_QUOTES, 'UTF-8')?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'account_status','Account Status')?></span>
                  <select name="account_status">
                    <option value="active" <?=$statusKey==='active'?'selected':''?>><?=t($t,'status_active','Active')?></option>
                    <option value="pending" <?=$statusKey==='pending'?'selected':''?>><?=t($t,'status_pending','Pending approval')?></option>
                    <option value="disabled" <?=$statusKey==='disabled'?'selected':''?>><?=t($t,'status_disabled','Disabled')?></option>
                  </select>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'work_function','Work Function / Cadre')?></span>
                  <select name="work_function">
                    <?php foreach (WORK_FUNCTIONS as $function): ?>
                      <option value="<?=$function?>" <?=$r['work_function']===$function?'selected':''?>><?=htmlspecialchars(WORK_FUNCTION_LABELS[$function] ?? $function, ENT_QUOTES, 'UTF-8')?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'next_assessment','Next Assessment Date')?></span>
                  <input type="date" name="next_assessment_date" value="<?=htmlspecialchars($nextAssessment, ENT_QUOTES, 'UTF-8')?>">
                </label>
              </div>
              <div class="md-user-form-actions">
                <button name="reset" class="md-button md-elev-1"><?=t($t,'apply','Apply')?></button>
              </div>
            </form>
            <form method="post" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" class="md-user-delete-form" data-verify-user="<?=htmlspecialchars($r['username'], ENT_QUOTES, 'UTF-8')?>" data-verify-prompt="<?=htmlspecialchars(t($t,'confirm_delete_prompt','Type the username to confirm deletion.'), ENT_QUOTES, 'UTF-8')?>" data-verify-mismatch="<?=htmlspecialchars(t($t,'delete_verification_failed','The entered username did not match. No changes were made.'), ENT_QUOTES, 'UTF-8')?>">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="id" value="<?=$r['id']?>">
              <button name="delete" class="md-button md-danger md-elev-1" type="submit"><?=t($t,'delete','Delete')?></button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
(function() {
  const forms = document.querySelectorAll('[data-verify-user]');
  forms.forEach((form) => {
    form.addEventListener('submit', (event) => {
      const expected = form.dataset.verifyUser || '';
      const promptMessage = form.dataset.verifyPrompt || 'Type the username to confirm deletion.';
      const mismatch = form.dataset.verifyMismatch || 'The entered username did not match. No changes were made.';
      const response = window.prompt(promptMessage, '');
      if (response === null) {
        event.preventDefault();
        return;
      }
      if (expected && response.trim() !== expected) {
        window.alert(mismatch);
        event.preventDefault();
      }
    });
  });

  const searchInput = document.querySelector('[data-user-search]');
  const cards = Array.from(document.querySelectorAll('.md-user-card'));
  const grid = document.querySelector('.md-user-grid');

  function applySearch(term) {
    if (!grid) return;
    const value = term.trim().toLowerCase();
    let visibleCount = 0;
    cards.forEach((card) => {
      const last = (card.dataset.lastName || '').toLowerCase();
      const full = (card.dataset.fullName || '').toLowerCase();
      const username = (card.dataset.username || '').toLowerCase();
      const matches = !value || last.includes(value) || full.includes(value) || username.includes(value);
      card.classList.toggle('md-user-card--hidden', !matches);
      if (matches) {
        visibleCount++;
      }
    });
    grid.dataset.hasResults = visibleCount > 0 ? 'true' : 'false';
  }

  if (searchInput && cards.length) {
    searchInput.addEventListener('input', () => applySearch(searchInput.value));
    applySearch(searchInput.value || '');
  }
})();
</script>
</body></html>