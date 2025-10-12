<?php
require_once __DIR__ . '/config.php';
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$err = '';

$oauthProviders = [];
$googleEnabled = ((int)($cfg['google_oauth_enabled'] ?? 0) === 1)
    && !empty($cfg['google_oauth_client_id'])
    && !empty($cfg['google_oauth_client_secret']);
if ($googleEnabled) {
    $oauthProviders['google'] = t($t, 'sign_in_with_google', 'Sign in with Google');
}

$microsoftEnabled = ((int)($cfg['microsoft_oauth_enabled'] ?? 0) === 1)
    && !empty($cfg['microsoft_oauth_client_id'])
    && !empty($cfg['microsoft_oauth_client_secret']);
if ($microsoftEnabled) {
    $oauthProviders['microsoft'] = t($t, 'sign_in_with_microsoft', 'Sign in with Microsoft');
}

if (!empty($_SESSION['oauth_error'])) {
    $err = (string)$_SESSION['oauth_error'];
    unset($_SESSION['oauth_error']);
}
if (!empty($_SESSION['auth_error'])) {
    $err = (string)$_SESSION['auth_error'];
    unset($_SESSION['auth_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $u = $stmt->fetch();
    if ($u && password_verify($password, $u['password'])) {
        if (($u['account_status'] ?? 'active') === 'disabled') {
            $err = t($t, 'account_disabled', 'Your account has been disabled. Please contact your administrator.');
        } else {
            if (empty($u['first_login_at'])) {
                $pdo->prepare('UPDATE users SET first_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
                $u['first_login_at'] = date('Y-m-d H:i:s');
            }
            $_SESSION['user'] = $u;
            $_SESSION['lang'] = $u['language'] ?? ($_SESSION['lang'] ?? 'en');
            if (($u['account_status'] ?? 'active') === 'pending') {
                $_SESSION['pending_notice'] = true;
                header('Location: ' . url_for('profile.php?pending=1'));
            } else {
                header('Location: ' . url_for('my_performance.php'));
            }
            exit;
        }
    } else {
        $err = t($t,'invalid_login','Invalid username or password');
    }
}
$logoPath = (string)($cfg['logo_path'] ?? '');
if ($logoPath === '') {
    $logoPath = asset_url('assets/img/epss-logo.svg');
} elseif (!preg_match('#^https?://#i', $logoPath)) {
    $logoPath = asset_url(ltrim($logoPath, '/'));
}
$logo = htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8');
$site_name = htmlspecialchars($cfg['site_name'] ?? 'My Performance');
$landing_text = htmlspecialchars($cfg['landing_text'] ?? '');
$address = htmlspecialchars($cfg['address'] ?? '');
$contact = htmlspecialchars($cfg['contact'] ?? '');
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=$site_name?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>" style="<?=htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8')?>">
  <div id="google_translate_element" class="visually-hidden" aria-hidden="true"></div>
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

      <form method="post" class="md-form" action="<?=htmlspecialchars(url_for('index.php'), ENT_QUOTES, 'UTF-8')?>">
        <input type="hidden" name="csrf" value="<?=csrf_token()?>">
        <label class="md-field">
          <span><?=t($t,'username','Username')?></span>
          <input name="username" required>
        </label>
        <label class="md-field">
          <span><?=t($t,'password','Password')?></span>
          <input type="password" name="password" required>
        </label>
        <?php if (!empty($err)): ?><div class="md-alert"><?=htmlspecialchars($err, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
        <div class="md-form-actions md-form-actions--center md-login-actions">
          <button class="md-button md-primary md-elev-2"><?=t($t,'sign_in','Sign In')?></button>
        </div>
      </form>

      <?php if (!empty($oauthProviders)): ?>
        <div class="md-divider"></div>
        <div class="md-sso-buttons">
          <?php foreach ($oauthProviders as $provider => $label): ?>
            <a class="md-button md-elev-1 md-sso-btn <?=$provider?>" href="<?=htmlspecialchars(url_for('oauth.php?provider=' . $provider), ENT_QUOTES, 'UTF-8')?>"><?=$label?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="md-meta">
        <?php if ($address): ?><div class='md-small'><strong><?=t($t,'address_label','Address')?>: </strong><?=$address?></div><?php endif; ?>
        <?php if ($contact): ?><div class='md-small'><strong><?=t($t,'contact_label','Contact')?>: </strong><?=$contact?></div><?php endif; ?>
        <div class="md-small lang-switch">
          <a href="<?=htmlspecialchars(url_for('set_lang.php?lang=en'), ENT_QUOTES, 'UTF-8')?>">EN</a> ·
          <a href="<?=htmlspecialchars(url_for('set_lang.php?lang=am'), ENT_QUOTES, 'UTF-8')?>">AM</a> ·
          <a href="<?=htmlspecialchars(url_for('set_lang.php?lang=fr'), ENT_QUOTES, 'UTF-8')?>">FR</a>
        </div>
      </div>
    </div>
  </div>
  <script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
    window.APP_BASE_URL = <?=json_encode(BASE_URL, JSON_THROW_ON_ERROR)?>;
    window.APP_DEFAULT_LOCALE = <?=json_encode(AVAILABLE_LOCALES[0], JSON_THROW_ON_ERROR)?>;
    window.APP_AVAILABLE_LOCALES = <?=json_encode(AVAILABLE_LOCALES, JSON_THROW_ON_ERROR)?>;
  </script>
  <script src="<?=asset_url('assets/js/app.js')?>"></script>
</body>
</html>

