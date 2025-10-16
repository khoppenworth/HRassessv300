<?php
require_once __DIR__ . '/../config.php';

auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $site_name = trim($_POST['site_name'] ?? '');
    $landing_text = trim($_POST['landing_text'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $footer_org_name = trim($_POST['footer_org_name'] ?? '');
    $footer_org_short = trim($_POST['footer_org_short'] ?? '');
    $footer_website_label = trim($_POST['footer_website_label'] ?? '');
    $footer_website_url = trim($_POST['footer_website_url'] ?? '');
    $footer_email = trim($_POST['footer_email'] ?? '');
    $footer_phone = trim($_POST['footer_phone'] ?? '');
    $footer_hotline_label = trim($_POST['footer_hotline_label'] ?? '');
    $footer_hotline_number = trim($_POST['footer_hotline_number'] ?? '');
    $footer_rights = trim($_POST['footer_rights'] ?? '');
    if ($footer_website_url && !preg_match('#^https?://#i', $footer_website_url)) {
        $footer_website_url = 'https://' . ltrim($footer_website_url, '/');
    }

    $fields = [
        'site_name' => $site_name,
        'landing_text' => $landing_text,
        'address' => $address,
        'contact' => $contact,
        'logo_path' => null,
        'footer_org_name' => $footer_org_name,
        'footer_org_short' => $footer_org_short,
        'footer_website_label' => $footer_website_label,
        'footer_website_url' => $footer_website_url,
        'footer_email' => $footer_email,
        'footer_phone' => $footer_phone,
        'footer_hotline_label' => $footer_hotline_label,
        'footer_hotline_number' => $footer_hotline_number,
        'footer_rights' => $footer_rights,
    ];

    $assignments = [];
    $values = [];
    foreach ($fields as $column => $value) {
        $assignments[] = "$column=?";
        $values[] = ($value !== '') ? $value : null;
    }

    $stm = $pdo->prepare('UPDATE site_config SET ' . implode(', ', $assignments) . ' WHERE id=1');
    $stm->execute($values);
    $msg = t($t, 'branding_updated', 'Branding updated successfully.');
    $cfg = get_site_config($pdo);
}
?>
<!doctype html>
<html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars(t($t,'branding','Branding & Landing'), ENT_QUOTES, 'UTF-8')?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
  <link rel="manifest" href="<?=asset_url('manifest.php')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="<?=htmlspecialchars(site_body_classes($cfg), ENT_QUOTES, 'UTF-8')?>">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'branding','Branding & Landing')?></h2>
    <?php if ($msg): ?><div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <form method="post" action="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field"><span><?=t($t,'site_name','Site Name')?></span><input name="site_name" value="<?=htmlspecialchars($cfg['site_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'landing_text','Landing Text')?></span><textarea name="landing_text" rows="3"><?=htmlspecialchars($cfg['landing_text'] ?? '')?></textarea></label>
      <label class="md-field"><span><?=t($t,'address_label','Address')?></span><input name="address" value="<?=htmlspecialchars($cfg['address'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'contact_label','Contact')?></span><input name="contact" value="<?=htmlspecialchars($cfg['contact'] ?? '')?>"></label>
      <h3 class="md-subhead"><?=t($t,'footer_settings','Footer Details')?></h3>
      <label class="md-field"><span><?=t($t,'footer_org_name_label','Organization Name')?></span><input name="footer_org_name" value="<?=htmlspecialchars($cfg['footer_org_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_org_short_label','Organization Short Name')?></span><input name="footer_org_short" value="<?=htmlspecialchars($cfg['footer_org_short'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_website_label_label','Website Label')?></span><input name="footer_website_label" value="<?=htmlspecialchars($cfg['footer_website_label'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_website_url_label','Website URL')?></span><input name="footer_website_url" type="url" value="<?=htmlspecialchars($cfg['footer_website_url'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_email_label','Contact Email')?></span><input name="footer_email" type="email" value="<?=htmlspecialchars($cfg['footer_email'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_phone_label','Phone Number')?></span><input name="footer_phone" value="<?=htmlspecialchars($cfg['footer_phone'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_hotline_label_label','Hotline Label')?></span><input name="footer_hotline_label" value="<?=htmlspecialchars($cfg['footer_hotline_label'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_hotline_number_label','Hotline Number')?></span><input name="footer_hotline_number" value="<?=htmlspecialchars($cfg['footer_hotline_number'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'footer_rights_label','Rights Statement')?></span><input name="footer_rights" value="<?=htmlspecialchars($cfg['footer_rights'] ?? '')?>"></label>
      <div class="md-field">
        <span><?=t($t,'logo','Logo')?></span>
        <p class="md-hint"><?=t($t,'logo_auto_generated','The system automatically generates a logo based on your brand colors.')?></p>
      </div>
      <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
