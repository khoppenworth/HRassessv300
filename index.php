<?php
require_once __DIR__ . '/config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';

$logoRenderPath = site_logo_url($cfg);

$logo = htmlspecialchars($logoRenderPath, ENT_QUOTES, 'UTF-8');
$logoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
$siteName = htmlspecialchars($cfg['site_name'] ?? 'My Performance', ENT_QUOTES, 'UTF-8');
$landingText = htmlspecialchars($cfg['landing_text'] ?? '', ENT_QUOTES, 'UTF-8');
$address = htmlspecialchars($cfg['address'] ?? '', ENT_QUOTES, 'UTF-8');
$contact = htmlspecialchars($cfg['contact'] ?? '', ENT_QUOTES, 'UTF-8');
$bodyClass = htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8');
$bodyStyle = htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8');
$brandStyle = site_brand_style($cfg);
$baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8');
$langAttr = htmlspecialchars($locale, ENT_QUOTES, 'UTF-8');
$loginUrl = htmlspecialchars(url_for('login.php'), ENT_QUOTES, 'UTF-8');
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
        <p class="md-subtitle"><?= t($t, 'landing_intro', 'Welcome to the performance management portal. Discover resources and updates about your organisation\'s assessment program.') ?></p>
      <?php endif; ?>

      <div class="md-form-actions md-form-actions--center md-login-actions">
        <a class="md-button md-primary md-elev-2" href="<?= $loginUrl ?>">
          <?= t($t, 'sign_in', 'Sign In') ?>
        </a>
      </div>

      <div class="md-meta">
        <?php if ($address !== ''): ?>
          <div class="md-small"><strong><?= t($t, 'address_label', 'Address') ?>:</strong> <?= $address ?></div>
        <?php endif; ?>
        <?php if ($contact !== ''): ?>
          <div class="md-small"><strong><?= t($t, 'contact_label', 'Contact') ?>:</strong> <?= $contact ?></div>
        <?php endif; ?>
        <div class="md-small">
          <a href="<?= $loginUrl ?>"><?= t($t, 'login_now', 'Go to secure login') ?></a>
        </div>
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
