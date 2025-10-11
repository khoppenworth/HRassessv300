<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$msg='';
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
            header('Location: ' . url_for('admin/users.php'));
            exit;
        }
    }
}
$rows = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
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
<button name="create" class="md-button md-primary md-elev-2"><?=t($t,'create','Create')?></button>
</form></div>

<div class="md-card md-elev-2"><h2 class="md-card-title"><?=t($t,'manage_users','Manage Users')?></h2>
<table class="md-table">
  <thead><tr><th><?=t($t,'id','ID')?></th><th><?=t($t,'username','Username')?></th><th><?=t($t,'role','Role')?></th><th><?=t($t,'full_name','Full Name')?></th><th><?=t($t,'email','Email')?></th><th><?=t($t,'work_function','Work Function / Cadre')?></th><th><?=t($t,'account_status','Account Status')?></th><th><?=t($t,'next_assessment','Next Assessment')?></th><th><?=t($t,'reset','Reset')?></th><th><?=t($t,'delete','Delete')?></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?=$r['id']?></td>
    <td><?=htmlspecialchars($r['username'])?></td>
    <td><?=$r['role']?></td>
    <td><?=htmlspecialchars($r['full_name'])?></td>
    <td><?=htmlspecialchars($r['email'])?></td>
    <td><?=htmlspecialchars(WORK_FUNCTION_LABELS[$r['work_function']] ?? $r['work_function'])?></td>
    <?php
      $statusKey = $r['account_status'] ?? 'active';
      $statusLabels = [
        'active' => t($t,'status_active','Active'),
        'pending' => t($t,'status_pending','Pending approval'),
        'disabled' => t($t,'status_disabled','Disabled')
      ];
    ?>
    <td><?=htmlspecialchars($statusLabels[$statusKey] ?? $statusKey)?></td>
    <td><?=htmlspecialchars($r['next_assessment_date'] ?? '-')?></td>
    <td>
      <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="id" value="<?=$r['id']?>">
        <input name="new_password" placeholder="<?=htmlspecialchars(t($t,'new_password_reset','New Password'), ENT_QUOTES, 'UTF-8')?>" required>
          <select name="role">
            <option value="staff" <?=$r['role']=='staff'?'selected':''?>><?=t($t,'role_staff','staff')?></option>
            <option value="supervisor" <?=$r['role']=='supervisor'?'selected':''?>><?=t($t,'role_supervisor','supervisor')?></option>
            <option value="admin" <?=$r['role']=='admin'?'selected':''?>><?=t($t,'role_admin','admin')?></option>
          </select>
        <select name="account_status">
          <option value="active" <?=$r['account_status']==='active'?'selected':''?>><?=t($t,'status_active','Active')?></option>
          <option value="pending" <?=$r['account_status']==='pending'?'selected':''?>><?=t($t,'status_pending','Pending approval')?></option>
          <option value="disabled" <?=$r['account_status']==='disabled'?'selected':''?>><?=t($t,'status_disabled','Disabled')?></option>
        </select>
        <select name="work_function">
          <?php foreach (WORK_FUNCTIONS as $function): ?>
            <option value="<?=$function?>" <?=$r['work_function']===$function?'selected':''?>><?=htmlspecialchars(WORK_FUNCTION_LABELS[$function] ?? $function)?></option>
          <?php endforeach; ?>
        </select>
        <input type="date" name="next_assessment_date" value="<?=htmlspecialchars($r['next_assessment_date'] ?? '')?>">
        <button name="reset" class="md-button md-elev-1"><?=t($t,'apply','Apply')?></button>
      </form>
    </td>
    <td>
      <form method="post" class="md-inline-form" action="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" onsubmit="return confirm('<?=htmlspecialchars(t($t,'confirm_delete','Delete this record?'), ENT_QUOTES, 'UTF-8')?>');">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="id" value="<?=$r['id']?>">
        <button name="delete" class="md-button md-danger md-elev-1" type="submit"><?=t($t,'delete','Delete')?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>