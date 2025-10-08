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
    $logo_path = $cfg['logo_path'] ?? null;

    if (!empty($_FILES['logo']['tmp_name'])) {
        $dir = base_path('assets/uploads');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $original = basename($_FILES['logo']['name']);
        $fn = 'logo_' . time() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $original);
        $dest = $dir . '/' . $fn;
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $_FILES['logo']['tmp_name']) : null;
        if ($finfo) {
            finfo_close($finfo);
        }
        if (in_array($mime, ['image/png', 'image/jpeg', 'image/svg+xml', 'image/gif'], true)) {
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                $logo_path = 'assets/uploads/' . $fn;
            }
        } else {
            $msg = t($t, 'invalid_file_type', 'Invalid file type.');
        }
    }

    $stm = $pdo->prepare('UPDATE site_config SET site_name=?, landing_text=?, address=?, contact=?, logo_path=? WHERE id=1');
    $stm->execute([$site_name ?: null, $landing_text ?: null, $address ?: null, $contact ?: null, $logo_path ?: null]);
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
  <link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
  <link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head>
<body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'branding','Branding & Landing')?></h2>
    <?php if ($msg): ?><div class="md-alert"><?=htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field"><span><?=t($t,'site_name','Site Name')?></span><input name="site_name" value="<?=htmlspecialchars($cfg['site_name'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'landing_text','Landing Text')?></span><textarea name="landing_text" rows="3"><?=htmlspecialchars($cfg['landing_text'] ?? '')?></textarea></label>
      <label class="md-field"><span><?=t($t,'address_label','Address')?></span><input name="address" value="<?=htmlspecialchars($cfg['address'] ?? '')?>"></label>
      <label class="md-field"><span><?=t($t,'contact_label','Contact')?></span><input name="contact" value="<?=htmlspecialchars($cfg['contact'] ?? '')?>"></label>
      <div class="md-field">
        <span><?=t($t,'logo','Logo')?></span>
        <input type="file" name="logo" accept="image/*">
        <?php if (!empty($cfg['logo_path'])): ?>
          <?php $logoSrc = $cfg['logo_path'];
          if (!preg_match('#^https?://#i', (string)$logoSrc)) {
              $logoSrc = asset_url(ltrim((string)$logoSrc, '/'));
          }
          ?>
          <div class="md-thumb"><img src="<?=htmlspecialchars($logoSrc, ENT_QUOTES, 'UTF-8')?>" alt="Logo" height="40"></div>
        <?php endif; ?>
      </div>
      <button class="md-button md-primary md-elev-2"><?=t($t,'save','Save Changes')?></button>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body>
</html>
