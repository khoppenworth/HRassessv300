<?php
require_once __DIR__.'/config.php';
$t = load_lang($_SESSION['lang'] ?? 'en');
$cfg = get_site_config($pdo);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username=?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password'])) {
        if (empty($u['first_login_at'])) {
            $pdo->prepare('UPDATE users SET first_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
        }
        $_SESSION['user'] = $u;
        $_SESSION['lang'] = $u['language'] ?? ($_SESSION['lang'] ?? 'en');
        header('Location: dashboard.php'); exit;
    } else {
        $err = t($t,'invalid_login','Invalid username or password');
    }
}
$logo = $cfg['logo_path'] ? htmlspecialchars($cfg['logo_path']) : 'assets/img/epss-logo.svg';
$site_name = htmlspecialchars($cfg['site_name'] ?? 'My Performance');
$landing_text = htmlspecialchars($cfg['landing_text'] ?? '');
$address = htmlspecialchars($cfg['address'] ?? '');
$contact = htmlspecialchars($cfg['contact'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?=$site_name?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="assets/css/material.css">
  <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="md-bg">
  <div class="md-container">
    <div class="md-card md-elev-3 md-login">
      <div class="md-card-media">
        <img src="<?=$logo?>" alt="Logo" class="md-logo">
        <h1 class="md-title"><?=$site_name?></h1>
      </div>
      <?php if ($landing_text): ?>
        <p class="md-subtitle"><?=$landing_text?></p>
      <?php else: ?>
        <p class="md-subtitle"><?=t($t,'welcome_msg','Sign in to start your self-assessment and track your performance over time.')?></p>
      <?php endif; ?>

      <form method="post" class="md-form">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <label class="md-field">
          <span><?=t($t,'username','Username')?></span>
          <input name="username" required>
        </label>
        <label class="md-field">
          <span><?=t($t,'password','Password')?></span>
          <input type="password" name="password" required>
        </label>
        <?php if (!empty($err)): ?><div class="md-alert"><?=$err?></div><?php endif; ?>
        <button class="md-button md-primary md-elev-2"><?=t($t,'sign_in','Sign In')?></button>
      </form>

      <div class="md-meta">
        <?="{$address}" ? "<div class='md-small'><strong>Address: </strong>{$address}</div>" : ""?>
        <?="{$contact}" ? "<div class='md-small'><strong>Contact: </strong>{$contact}</div>" : ""?>
        <div class="md-small lang-switch">
          <a href="set_lang.php?lang=en">EN</a> · <a href="set_lang.php?lang=am">AM</a> · <a href="set_lang.php?lang=fr">FR</a>
        </div>
      </div>
    </div>
  </div>
  <script src="assets/js/app.js"></script>
</body>
</html>