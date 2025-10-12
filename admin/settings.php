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
$errors = [];
$enabledLocales = site_enabled_locales($cfg);

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

    $smtp_enabled = isset($_POST['smtp_enabled']) ? 1 : 0;
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = (int)($_POST['smtp_port'] ?? 0);
    $smtp_username = trim($_POST['smtp_username'] ?? '');
    $smtp_password_input = trim($_POST['smtp_password'] ?? '');
    $smtp_password = $smtp_password_input !== '' ? $smtp_password_input : (string)($cfg['smtp_password'] ?? '');
    $smtp_encryption = strtolower(trim($_POST['smtp_encryption'] ?? 'none'));
    if (!in_array($smtp_encryption, ['none','tls','ssl'], true)) {
        $smtp_encryption = 'none';
    }
    $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
    $smtp_from_name = trim($_POST['smtp_from_name'] ?? '');
    $smtp_timeout = (int)($_POST['smtp_timeout'] ?? 20);
    if ($smtp_timeout <= 0) {
        $smtp_timeout = 20;
    }

    $color_theme = strtolower(trim($_POST['color_theme'] ?? 'light'));
    if (!array_key_exists($color_theme, $themes)) {
        $color_theme = 'light';
    }

    $brand_color_reset = $_POST['brand_color_reset'] ?? '0';
    $brand_color_input = strtolower(trim($_POST['brand_color'] ?? ''));
    $brand_color = '';
    if ($brand_color_reset === '1') {
        $brand_color = '';
    } elseif ($brand_color_input !== '') {
        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $brand_color_input)) {
            if (strlen($brand_color_input) === 4) {
                $brand_color_input = '#' . $brand_color_input[1] . $brand_color_input[1] . $brand_color_input[2] . $brand_color_input[2] . $brand_color_input[3] . $brand_color_input[3];
            }
            $brand_color = strtolower($brand_color_input);
        }
    }

    $enabledLocalesInput = $_POST['enabled_locales'] ?? [];
    if (!is_array($enabledLocalesInput)) {
        $enabledLocalesInput = [];
    }
    $selectedLocales = sanitize_locale_selection($enabledLocalesInput);
    if (!array_intersect($selectedLocales, ['en', 'fr'])) {
        $errors[] = t($t, 'language_required_notice', 'At least English or French must remain enabled.');
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
        'brand_color' => $brand_color !== '' ? $brand_color : null,
        'smtp_enabled' => $smtp_enabled,
        'smtp_host' => $smtp_host !== '' ? $smtp_host : null,
        'smtp_port' => $smtp_port > 0 ? $smtp_port : 587,
        'smtp_username' => $smtp_username !== '' ? $smtp_username : null,
        'smtp_password' => $smtp_password !== '' ? $smtp_password : null,
        'smtp_encryption' => $smtp_encryption,
        'smtp_from_email' => $smtp_from_email !== '' ? $smtp_from_email : null,
        'smtp_from_name' => $smtp_from_name !== '' ? $smtp_from_name : null,
        'smtp_timeout' => $smtp_timeout,
    ];

    if ($errors === []) {
        $enabledLocales = enforce_locale_requirements($selectedLocales);
        $fields['enabled_locales'] = encode_enabled_locales($enabledLocales);

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
        $enabledLocales = site_enabled_locales($cfg);
    } else {
        $enabledLocales = $selectedLocales;
    }
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
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>" style="<?=htmlspecialchars(site_body_style($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'settings','Settings')?></h2>
    <?php if ($msg): ?><div class="md-alert success"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <?php if ($errors): ?>
      <div class="md-alert error">
        <?php foreach ($errors as $error): ?>
          <p><?=htmlspecialchars($error, ENT_QUOTES, 'UTF-8')?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
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
      <label class="md-field md-field-inline">
        <span><?=t($t,'brand_color','Brand Color')?></span>
        <div class="md-color-picker" data-brand-color-picker data-default-color="<?=htmlspecialchars(DEFAULT_BRAND_COLOR, ENT_QUOTES, 'UTF-8')?>">
          <input type="color" name="brand_color" value="<?=htmlspecialchars(site_brand_color($cfg), ENT_QUOTES, 'UTF-8')?>" aria-label="<?=t($t,'brand_color_picker','Choose a brand color')?>">
          <span class="md-color-value"><?=htmlspecialchars(strtoupper(site_brand_color($cfg)), ENT_QUOTES, 'UTF-8')?></span>
          <button type="button" class="md-button md-outline md-compact" data-brand-color-reset><?=t($t,'brand_color_reset','Use default brand color')?></button>
          <input type="hidden" name="brand_color_reset" value="0" data-brand-color-reset-field>
        </div>
        <small class="md-field-hint"><?=t($t,'brand_color_hint','Pick any brand color to personalize buttons, highlights, and gradients.')?></small>
      </label>
      <h3 class="md-subhead"><?=t($t,'language_settings','Languages')?></h3>
      <p class="md-field-hint"><?=t($t,'language_settings_hint','Choose which interface languages are available to users.')?></p>
      <?php foreach (SUPPORTED_LOCALES as $localeOption): ?>
        <?php $isChecked = in_array($localeOption, $enabledLocales, true); ?>
        <div class="md-control">
          <label>
            <input type="checkbox" name="enabled_locales[]" value="<?=htmlspecialchars($localeOption, ENT_QUOTES, 'UTF-8')?>" <?=$isChecked ? 'checked' : ''?>>
            <span><?=htmlspecialchars(t($t, 'language_label_' . $localeOption, locale_display_name($localeOption)), ENT_QUOTES, 'UTF-8')?></span>
          </label>
        </div>
      <?php endforeach; ?>
      <p class="md-field-hint"><?=t($t,'language_required_notice','At least English or French must remain enabled.')?></p>
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
      <h3 class="md-subhead"><?=t($t,'email_notifications','Email Notifications')?></h3>
      <div class="md-control">
        <label>
          <input type="checkbox" name="smtp_enabled" value="1" <?=((int)($cfg['smtp_enabled'] ?? 0) === 1) ? 'checked' : ''?>>
          <span><?=t($t,'enable_smtp_notifications','Enable SMTP notifications')?></span>
        </label>
      </div>
      <label class="md-field"><span><?=t($t,'smtp_host','SMTP Host')?></span><input name="smtp_host" value="<?=htmlspecialchars($cfg['smtp_host'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_port','SMTP Port')?></span><input type="number" name="smtp_port" min="1" value="<?=htmlspecialchars((string)($cfg['smtp_port'] ?? 587))?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_encryption','Encryption')?></span>
        <?php $enc = strtolower((string)($cfg['smtp_encryption'] ?? 'none')); ?>
        <select name="smtp_encryption">
          <option value="none" <?=$enc==='none'?'selected':''?>><?=t($t,'smtp_encryption_none','None')?></option>
          <option value="tls" <?=$enc==='tls'?'selected':''?>>TLS</option>
          <option value="ssl" <?=$enc==='ssl'?'selected':''?>>SSL</option>
        </select>
      </label>
      <label class="md-field"><span><?=t($t,'smtp_username','SMTP Username')?></span><input name="smtp_username" value="<?=htmlspecialchars($cfg['smtp_username'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_password','SMTP Password')?></span><input type="password" name="smtp_password" placeholder="<?=htmlspecialchars(t($t,'leave_blank_keep_password','Leave blank to keep current password.'), ENT_QUOTES, 'UTF-8')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_from_email','From Email')?></span><input name="smtp_from_email" value="<?=htmlspecialchars($cfg['smtp_from_email'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_from_name','From Name')?></span><input name="smtp_from_name" value="<?=htmlspecialchars($cfg['smtp_from_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'smtp_timeout','Connection Timeout (seconds)')?></span><input type="number" name="smtp_timeout" min="5" value="<?=htmlspecialchars((string)($cfg['smtp_timeout'] ?? 20))?>"></label>
      <div class="md-form-actions">
        <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
      </div>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
