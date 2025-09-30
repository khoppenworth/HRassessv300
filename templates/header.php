<?php
require_once __DIR__.'/../config.php';
$t = load_lang($_SESSION['lang'] ?? 'en');
$cfg = get_site_config($pdo);
?>
<header class="md-appbar md-elev-2">
  <div class="md-appbar-title"><?=htmlspecialchars($cfg['site_name'] ?? 'EPSS Self-Assessment')?></div>
  <nav class="md-appbar-actions">
    <a href="/set_lang.php?lang=en">EN</a>
    <a href="/set_lang.php?lang=am">AM</a>
    <a href="/set_lang.php?lang=fr">FR</a>
    <a href="/logout.php"><?=t($t,'logout','Logout')?> (<?=htmlspecialchars($_SESSION['user']['username'] ?? '')?>)</a>
  </nav>
</header>
<aside class="md-drawer">
  <div class="md-drawer-header">
    <img src="<?=htmlspecialchars($cfg['logo_path'] ?? '/assets/img/epss-logo.svg')?>" alt="Logo" class="md-logo-sm">
    <div class="md-drawer-title"><?=htmlspecialchars($cfg['site_name'] ?? 'EPSS')?></div>
  </div>
  <nav class="md-drawer-nav">
    <a href="/dashboard.php" class="md-drawer-link"><?=t($t,'dashboard','Dashboard')?></a>
    <a href="/submit_assessment.php" class="md-drawer-link"><?=t($t,'submit_assessment','Submit Assessment')?></a>
    <a href="/performance.php" class="md-drawer-link"><?=t($t,'performance','Performance')?></a>
    <a href="/profile.php" class="md-drawer-link"><?=t($t,'profile','Profile')?></a>
    <?php if (in_array($_SESSION['user']['role'] ?? '', ['admin','supervisor'])): ?>
      <a href="/admin/supervisor_review.php" class="md-drawer-link"><?=t($t,'review_queue','Review Queue')?></a>
    <?php endif; ?>
    <?php if (($_SESSION['user']['role'] ?? '')==='admin'): ?>
      <a href="/admin/dashboard.php" class="md-drawer-link"><?=t($t,'admin','Admin')?></a>
      <a href="/admin/branding.php" class="md-drawer-link"><?=t($t,'branding','Branding & Landing')?></a>
    <?php endif; ?>
  </nav>
</aside>
<main class="md-main">