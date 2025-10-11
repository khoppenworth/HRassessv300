<?php
require_once __DIR__ . '/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);

$themes = [
    'light' => t($t, 'theme_light', 'Light'),
    'dark' => t($t, 'theme_dark', 'Dark'),
];

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $google_oauth_enabled = isset($_POST['google_oauth_enabled']) ? 1 : 0;
    $google_oauth_client_id = trim($_POST['google_oauth_client_id'] ?? '');
    $google_oauth_client_secret = trim($_POST['google_oauth_client_secret'] ?? '');
    $microsoft_oauth_enabled = isset($_POST['microsoft_oauth_enabled']) ? 1 : 0;
    $microsoft_oauth_client_id = trim($_POST['microsoft_oauth_client_id'] ?? '');
    $microsoft_oauth_client_secret = trim($_POST['microsoft_oauth_client_secret'] ?? '');
    $microsoft_oauth_tenant = trim($_POST['microsoft_oauth_tenant'] ?? '');
    if ($microsoft_oauth_tenant === '') {
        $microsoft_oauth_tenant = 'common';
    }

    $color_theme = strtolower(trim($_POST['color_theme'] ?? 'light'));
    if (!array_key_exists($color_theme, $themes)) {
        $color_theme = 'light';
    }

    $fields = [
        'google_oauth_enabled' => $google_oauth_enabled,
        'google_oauth_client_id' => $google_oauth_client_id,
        'google_oauth_client_secret' => $google_oauth_client_secret,
        'microsoft_oauth_enabled' => $microsoft_oauth_enabled,
        'microsoft_oauth_client_id' => $microsoft_oauth_client_id,
        'microsoft_oauth_client_secret' => $microsoft_oauth_client_secret,
        'microsoft_oauth_tenant' => $microsoft_oauth_tenant,
        'color_theme' => $color_theme,
    ];

    $assignments = [];
    $values = [];
    foreach ($fields as $column => $value) {
        $assignments[] = "$column=?";
        $values[] = ($value !== '') ? $value : null;
    }

    $stm = $pdo->prepare('UPDATE site_config SET ' . implode(', ', $assignments) . ' WHERE id=1');
    $stm->execute($values);
    $msg = t($t, 'settings_updated', 'Settings updated successfully.');
    $cfg = get_site_config($pdo);
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'settings','Settings'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'settings','Settings')?></h2>
    <?php if ($msg): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <form method="post" action="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <h3 class="md-subhead"><?=t($t,'appearance_settings','Appearance')?></h3>
      <label class="md-field">
        <span><?=t($t,'color_theme','Color Theme')?></span>
        <select name="color_theme">
          <?php foreach ($themes as $themeValue => $themeLabel): ?>
            <option value="<?=htmlspecialchars($themeValue, ENT_QUOTES, 'UTF-8')?>" <?=site_color_theme($cfg) === $themeValue ? 'selected' : ''?>><?=htmlspecialchars($themeLabel, ENT_QUOTES, 'UTF-8')?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <h3 class="md-subhead"><?=t($t,'sso_settings','Single Sign-On (SSO)')?></h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="google_oauth_enabled" value="1" <?=((int)($cfg['google_oauth_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_google_sign_in','Enable Google sign-in')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'google_client_id','Google Client ID')?></span><input name="google_oauth_client_id" value="<?=htmlspecialchars($cfg['google_oauth_client_id'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'google_client_secret','Google Client Secret')?></span><input type="password" name="google_oauth_client_secret" value="<?=htmlspecialchars($cfg['google_oauth_client_secret'] ?? '')?>"></label>
      <div class="md-control">
        <label>
          <input type="checkbox" name="microsoft_oauth_enabled" value="1" <?=((int)($cfg['microsoft_oauth_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_microsoft_sign_in','Enable Microsoft sign-in')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'microsoft_client_id','Microsoft Client ID')?></span><input name="microsoft_oauth_client_id" value="<?=htmlspecialchars($cfg['microsoft_oauth_client_id'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'microsoft_client_secret','Microsoft Client Secret')?></span><input type="password" name="microsoft_oauth_client_secret" value="<?=htmlspecialchars($cfg['microsoft_oauth_client_secret'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'microsoft_tenant','Microsoft Tenant (directory)')?></span><input name="microsoft_oauth_tenant" value="<?=htmlspecialchars($cfg['microsoft_oauth_tenant'] ?? 'common')?>"></label>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
