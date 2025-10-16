<?php
require_once __DIR__ . '/config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';
$err = '';

$oauthProviders = [];
if (
    (int)($cfg['google_oauth_enabled'] ?? 0) === 1
    && !empty($cfg['google_oauth_client_id'])
    && !empty($cfg['google_oauth_client_secret'])
) {
    $oauthProviders['google'] = t($t, 'sign_in_with_google', 'Sign in with Google');
}
if (
    (int)($cfg['microsoft_oauth_enabled'] ?? 0) === 1
    && !empty($cfg['microsoft_oauth_client_id'])
    && !empty($cfg['microsoft_oauth_client_secret'])
) {
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
            $_SESSION['lang'] = resolve_locale($u['language'] ?? ($_SESSION['lang'] ?? 'en'));

            if (($u['account_status'] ?? 'active') === 'pending') {
                $_SESSION['pending_notice'] = true;
                header('Location: ' . url_for('profile.php?pending=1'));
            } else {
                header('Location: ' . url_for('my_performance.php'));
            }
            exit;
        }
    } else {
        $err = t($t, 'invalid_login', 'Invalid username or password');
    }
}

$logoRenderPath = site_logo_url($cfg);

$logo = htmlspecialchars($logoRenderPath, ENT_QUOTES, 'UTF-8');
$logoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars($cfg['site_name'] ?? 'My Performance', ENT_QUOTES, 'UTF-8');
$landingText = htmlspecialchars($cfg['landing_text'] ?? '', ENT_QUOTES, 'UTF-8');
$address = htmlspecialchars($cfg['address'] ?? '', ENT_QUOTES, 'UTF-8');
$contact = htmlspecialchars($cfg['contact'] ?? '', ENT_QUOTES, 'UTF-8');
$bodyClass = htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8');
$bodyStyle = htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8');
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$brandStyle = site_brand_style($cfg);
?>
<!doctype html>
<html lang="<?= $langAttr ?>" data-base-url="<?= $baseUrl ?>">
<head>
  <meta charset="utf-8">
  <title><?= $siteName ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?= $baseUrl ?>">
  <link rel="manifest" href="<?= asset_url('manifest.php') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/material.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('assets/css/styles.css') ?>">
  <?php if ($brandStyle !== ''): ?>
    <style id="md-brand-style"><?= htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8') ?></style>
  <?php endif; ?>
</head>
<body class="<?= $bodyClass ?>" style="<?= $bodyStyle ?>">
  <div id="google_translate_element" class="visually-hidden" aria-hidden="true"></div>
  <div class="md-container">
    <div class="md-card md-elev-3 md-login">
      <div class="md-card-media">
        <img src="<?= $logo ?>" alt="<?= $logoAlt ?>" class="md-logo">
        <h1 class="md-title"><?= $siteName ?></h1>
      </div>

      <?php if ($landingText !== ''): ?>
        <p class="md-subtitle"><?= $landingText ?></p>
      <?php else: ?>
        <p class="md-subtitle"><?= t($t, 'welcome_msg', 'Sign in to start your self-assessment and track your performance over time.') ?></p>
      <?php endif; ?>

      <form method="post" class="md-form" action="<?= htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <label class="md-field">
          <span><?= t($t, 'username', 'Username') ?></span>
          <input name="username" required>
        </label>
        <label class="md-field">
          <span><?= t($t, 'password', 'Password') ?></span>
          <input type="password" name="password" required>
        </label>
        <?php if ($err !== ''): ?>
          <div class="md-alert"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="md-form-actions md-form-actions--center md-login-actions">
          <button class="md-button md-primary md-elev-2" type="submit">
            <?= t($t, 'sign_in', 'Sign In') ?>
          </button>
        </div>
      </form>

      <?php if (!empty($oauthProviders)): ?>
        <div class="md-divider"></div>
        <div class="md-sso-buttons">
          <?php foreach ($oauthProviders as $provider => $label): ?>
            <a
              class="md-button md-elev-1 md-sso-btn <?= htmlspecialchars($provider, ENT_QUOTES, 'UTF-8') ?>"
              href="<?= htmlspecialchars(url_for('oauth.php?provider=' . $provider), ENT_QUOTES, 'UTF-8') ?>"
            ><?= $label ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="md-meta">
        <?php if ($address !== ''): ?>
          <div class="md-small"><strong><?= t($t, 'address_label', 'Address') ?>:</strong> <?= $address ?></div>
        <?php endif; ?>
        <?php if ($contact !== ''): ?>
          <div class="md-small"><strong><?= t($t, 'contact_label', 'Contact') ?>:</strong> <?= $contact ?></div>
        <?php endif; ?>
        <div class="md-small lang-switch">
          <?php
          $links = [];
          foreach ($availableLocales as $loc) {
              $url = htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8');
              $label = htmlspecialchars(strtoupper($loc), ENT_QUOTES, 'UTF-8');
              $links[] = "<a href='" . $url . "'>" . $label . "</a>";
          }
          echo implode(' · ', $links);
          ?>
        </div>
      </div>
    </div>
  </div>

  <script nonce="<?= htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8') ?>">
    window.APP_BASE_URL = <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>;
    window.APP_DEFAULT_LOCALE = <?= json_encode($defaultLocale, JSON_THROW_ON_ERROR) ?>;
    window.APP_AVAILABLE_LOCALES = <?= json_encode($availableLocales, JSON_THROW_ON_ERROR) ?>;
  </script>
  <script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
