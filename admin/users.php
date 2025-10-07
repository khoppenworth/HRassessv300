<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$t = load_lang($_SESSION['lang'] ?? 'en');

$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    if (isset($_POST['create'])) {
        $u = trim($_POST['username']);
        $p = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $r = in_array($_POST['role'], ['admin','supervisor','staff'], true) ? $_POST['role'] : 'staff';
        $wf = $_POST['work_function'] ?? 'general_service';
        if (!in_array($wf, WORK_FUNCTIONS, true)) { $wf = 'general_service'; }
        $stm = $pdo->prepare("INSERT INTO users (username,password,role,full_name,email,work_function) VALUES (?,?,?,?,?,?)");
        $stm->execute([$u,$p,$r, $_POST['full_name'] ?? null, $_POST['email'] ?? null, $wf]);
        $msg='User created';
    }
    if (isset($_POST['reset'])) {
        $id = (int)$_POST['id'];
        $p = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        $wf = $_POST['work_function'] ?? 'general_service';
        if (!in_array($wf, WORK_FUNCTIONS, true)) { $wf = 'general_service'; }
        $stm = $pdo->prepare("UPDATE users SET password=?, role=?, work_function=?, profile_completed=0 WHERE id=?");
        $stm->execute([$p, $_POST['role'], $wf, $id]);
        $msg='Updated';
    }
}
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
    header('Location: users.php'); exit;
}
$rows = $pdo->query("SELECT * FROM users ORDER BY id DESC")->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><title>Users</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/material.css">
<link rel="stylesheet" href="/assets/css/styles.css"></head>
<body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
<?php if ($msg): ?><div class="md-alert"><?=$msg?></div><?php endif; ?>
<div class="md-card md-elev-2"><h2 class="md-card-title">Create User</h2>
<form method="post" class="md-form-grid">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">
<label class="md-field"><span>Username</span><input name="username" required></label>
<label class="md-field"><span>Password</span><input name="password" type="password" required></label>
<label class="md-field"><span>Role</span><select name="role"><option>staff</option><option>supervisor</option><option>admin</option></select></label>
<label class="md-field"><span>Full name</span><input name="full_name"></label>
<label class="md-field"><span>Email</span><input name="email"></label>
<label class="md-field"><span>Work Function</span>
  <select name="work_function">
    <?php foreach (WORK_FUNCTIONS as $function): ?>
      <option value="<?=$function?>"><?=htmlspecialchars(WORK_FUNCTION_LABELS[$function] ?? $function)?></option>
    <?php endforeach; ?>
  </select>
</label>
<button name="create" class="md-button md-primary md-elev-2">Create</button>
</form></div>

<div class="md-card md-elev-2"><h2 class="md-card-title">Users</h2>
<table class="md-table">
  <thead><tr><th>ID</th><th>User</th><th>Role</th><th>Name</th><th>Email</th><th>Work Function</th><th>Reset</th><th>Del</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?=$r['id']?></td>
    <td><?=htmlspecialchars($r['username'])?></td>
    <td><?=$r['role']?></td>
    <td><?=htmlspecialchars($r['full_name'])?></td>
    <td><?=htmlspecialchars($r['email'])?></td>
    <td><?=htmlspecialchars(WORK_FUNCTION_LABELS[$r['work_function']] ?? $r['work_function'])?></td>
    <td>
      <form method="post" class="md-inline-form">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <input type="hidden" name="id" value="<?=$r['id']?>">
        <input name="new_password" placeholder="new pass" required>
        <select name="role">
          <option <?=$r['role']=='staff'?'selected':''?>>staff</option>
          <option <?=$r['role']=='supervisor'?'selected':''?>>supervisor</option>
          <option <?=$r['role']=='admin'?'selected':''?>>admin</option>
        </select>
        <select name="work_function">
          <?php foreach (WORK_FUNCTIONS as $function): ?>
            <option value="<?=$function?>" <?=$r['work_function']===$function?'selected':''?>><?=htmlspecialchars(WORK_FUNCTION_LABELS[$function] ?? $function)?></option>
          <?php endforeach; ?>
        </select>
        <button name="reset" class="md-button md-elev-1">Apply</button>
      </form>
    </td>
    <td><a class="md-button md-danger md-elev-1" onclick="return confirm('Delete?')" href="users.php?delete=<?=$r['id']?>">Delete</a></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>