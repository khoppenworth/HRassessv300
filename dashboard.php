<?php
require_once __DIR__ . '/config.php';
auth_required();
refresh_current_user($pdo);
require_profile_completion($pdo);
$locale = ensure_locale();
$t = load_lang($locale);
$user = current_user();
$cfg = get_site_config($pdo);
?>
<!doctype html><html lang="<?=htmlspecialchars($locale, ENT_QUOTES, 'UTF-8')?>" data-base-url="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>"><head>
<meta charset="utf-8"><title><?=htmlspecialchars(t($t,'dashboard','Dashboard'), ENT_QUOTES, 'UTF-8')?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="app-base-url" content="<?=htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8')?>">
<link rel="manifest" href="<?=asset_url('manifest.webmanifest')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/material.css')?>">
<link rel="stylesheet" href="<?=asset_url('assets/css/styles.css')?>">
</head><body class="md-bg">
<?php include __DIR__.'/templates/header.php'; ?>
<section class="md-section">
  <div class="md-card md-elev-2">
    <h2 class="md-card-title"><?=t($t,'welcome','Welcome')?>, <?=htmlspecialchars($user['full_name'] ?? $user['username'])?></h2>
    <p><?=t($t,'dashboard_intro','Use the menu to submit self-assessments, track performance, or administer the system.')?></p>
  </div>
</section>
<?php include __DIR__.'/templates/footer.php'; ?>
</body></html>