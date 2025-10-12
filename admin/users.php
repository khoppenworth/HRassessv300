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
            } elseif (!in_array($role, ['admin','supervisor','staff'], true)) {
                $msg = t($t, 'invalid_role', 'Invalid role selection.');
            } else {
                if (!in_array($workFunction, WORK_FUNCTIONS, true)) { $workFunction = 'general_service'; }
                $hash = password_hash($password, PASSWORD_DEFAULT);
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
            if ($id <= 0 || $newPassword === '') {
                $msg = t($t, 'admin_reset_required', 'User and password are required for reset.');
            } elseif (!in_array($role, ['admin','supervisor','staff'], true)) {
                $msg = t($t, 'invalid_role', 'Invalid role selection.');
            } else {
                if (!in_array($workFunction, WORK_FUNCTIONS, true)) { $workFunction = 'general_service'; }
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stm = $pdo->prepare("UPDATE users SET password=?, role=?, work_function=?, account_status=?, next_assessment_date=?, profile_completed=0 WHERE id=?");
                $stm->execute([$hash, $role, $workFunction, $accountStatus, $nextAssessment, $id]);
                $msg = t($t, 'user_updated', 'User updated successfully.');
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
$roleLabels = [
    'staff' => t($t, 'role_staff', 'staff'),
    'supervisor' => t($t, 'role_supervisor', 'supervisor'),
    'admin' => t($t, 'role_admin', 'admin'),
];
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
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>"></head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
<?php if ($msg): ?><div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
<div class="md-card md-elev-2"><h2 class="md-card-title"><?=t($t,'create_user','Create User')?></h2>
<form method="post" class="md-form-grid" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<label class="md-field"><span><?=t($t,'username','Username')?></span><input name="username" required></label>
<label class="md-field"><span><?=t($t,'password','Password')?></span><input name="password" type="password" required></label>
  <label class="md-field"><span><?=t($t,'role','Role')?></span><select name="role"><option value="staff"><?=t($t,'role_staff','staff')?></option><option value="supervisor"><?=t($t,'role_supervisor','supervisor')?></option><option value="admin"><?=t($t,'role_admin','admin')?></option></select></label>
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
    <div class="md-user-grid">
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
        ?>
        <article class="md-user-card">
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
                  <input name="new_password" type="password" required autocomplete="new-password">
                </label>
                <label class="md-field md-field--compact">
                  <span><?=t($t,'role','Role')?></span>
                  <select name="role">
                    <option value="staff" <?=$roleKey==='staff'?'selected':''?>><?=t($t,'role_staff','staff')?></option>
                    <option value="supervisor" <?=$roleKey==='supervisor'?'selected':''?>><?=t($t,'role_supervisor','supervisor')?></option>
                    <option value="admin" <?=$roleKey==='admin'?'selected':''?>><?=t($t,'role_admin','admin')?></option>
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
})();
</script>
</body></html>