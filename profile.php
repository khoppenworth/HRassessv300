<?php
require_once __DIR__.'/config.php';
auth_required();
$t = load_lang($_SESSION['lang'] ?? 'en');
$msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $pass = $_POST['password'] ?? '';
    if (strlen($pass) < 6) {
        $msg = 'Password too short.';
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stm = $pdo->prepare("UPDATE users SET password=? WHERE id=?");
        $stm->execute([$hash, $_SESSION['user']['id']]);
        $msg = 'Updated.';
    }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><title><?=t($t,'profile','Profile')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="assets/css/material.css">
<link rel="stylesheet" href="assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'change_password','Change Password')?></h2>
    <?php if ($msg): ?><div class="md-alert"><?=$msg?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field">
        <span><?=t($t,'new_password','New Password')?></span>
        <input type="password" name="password" required minlength="6">
      </label>
      <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save')?></button>
    </form>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>