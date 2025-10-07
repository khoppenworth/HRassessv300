<?php
require_once __DIR__.'/../config.php';
auth_required(['admin']);
refresh_current_user($pdo);
require_profile_completion($pdo);
$t = load_lang($_SESSION['lang'] ?? 'en');
$cfg = get_site_config($pdo);
$msg='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $site_name = trim($_POST['site_name'] ?? '');
  $landing_text = trim($_POST['landing_text'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $contact = trim($_POST['contact'] ?? '');
  $logo_path = $cfg['logo_path'] ?? null;

  if (!empty($_FILES['logo']['tmp_name'])) {
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $fn = 'logo_'.time().'_'.basename($_FILES['logo']['name']);
    $dest = $dir . '/' . $fn;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['logo']['tmp_name']);
    finfo_close($finfo);
    if (in_array($mime, ['image/png','image/jpeg','image/svg+xml','image/gif'], true)) {
      move_uploaded_file($_FILES['logo']['tmp_name'], $dest);
      $logo_path = '/assets/uploads/'.$fn;
    } else {
      $msg = 'Invalid file type.';
    }
  }

  $stm = $pdo->prepare("UPDATE site_config SET site_name=?, landing_text=?, address=?, contact=?, logo_path=? WHERE id=1");
  $stm->execute([$site_name ?: null, $landing_text ?: null, $address ?: null, $contact ?: null, $logo_path ?: null]);
  $msg = 'Updated branding.';
  $cfg = get_site_config($pdo);
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Branding</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/material.css">
<link rel="stylesheet" href="/assets/css/styles.css">
</head><body class="md-bg">
<?php include __DIR__.'/../templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'branding','Branding & Landing')?></h2>
    <?php if ($msg): ?><div class="md-alert"><?=$msg?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <label class="md-field"><span>Site Name</span><input name="site_name" value="<?=htmlspecialchars($cfg['site_name'] ?? '')?>"></label>
      <label class="md-field"><span>Landing Text</span><textarea name="landing_text" rows="3"><?=htmlspecialchars($cfg['landing_text'] ?? '')?></textarea></label>
      <label class="md-field"><span>Address</span><input name="address" value="<?=htmlspecialchars($cfg['address'] ?? '')?>"></label>
      <label class="md-field"><span>Contact</span><input name="contact" value="<?=htmlspecialchars($cfg['contact'] ?? '')?>"></label>
      <div class="md-field">
        <span>Logo</span>
        <input type="file" name="logo" accept="image/*">
        <?php if (!empty($cfg['logo_path'])): ?><div class="md-thumb"><img src="<?=htmlspecialchars($cfg['logo_path'])?>" height="40"></div><?php endif; ?>
      </div>
      <button class="md-button md-primary md-elev-2">Save</button>
    </form>
  </div>
</section>
<?php include __DIR__.'/../templates/footer.php'; ?>
</body></html>