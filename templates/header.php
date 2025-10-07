<?php
require_once __DIR__.'/../config.php';
$t = load_lang($_SESSION['lang'] ?? 'en');
$cfg = get_site_config($pdo);
$user = current_user();
?>
<header class="md-appbar md-elev-2">
  <button class="md-appbar-toggle" aria-label="Toggle navigation" data-drawer-toggle>
    <span></span>
    <span></span>
    <span></span>
  </button>
  <div class="md-appbar-title"><?=htmlspecialchars($cfg['site_name'] ?? 'My Performance')?></div>
  <nav class="md-appbar-actions">
    <div class="md-lang-switch">
      <a href="/set_lang.php?lang=en" class="<?=($_SESSION['lang'] ?? 'en')==='en' ? 'active' : ''?>">EN</a>
      <a href="/set_lang.php?lang=am" class="<?=($_SESSION['lang'] ?? '')==='am' ? 'active' : ''?>">AM</a>
      <a href="/set_lang.php?lang=fr" class="<?=($_SESSION['lang'] ?? '')==='fr' ? 'active' : ''?>">FR</a>
    </div>
    <a href="/profile.php" class="md-appbar-link"><?=htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Profile')?></a>
    <a href="/logout.php" class="md-appbar-link"><?=t($t,'logout','Logout')?></a>
  </nav>
</header>
<aside class="md-drawer" data-drawer>
  <div class="md-drawer-header">
    <img src="<?=htmlspecialchars($cfg['logo_path'] ?? '/assets/img/epss-logo.svg')?>" alt="Logo" class="md-logo-sm">
    <div class="md-drawer-title"><?=htmlspecialchars($cfg['site_name'] ?? 'My Performance')?></div>
  </div>
  <nav class="md-drawer-nav">
    <div class="md-drawer-section">
      <span class="md-drawer-label"><?=t($t,'main_navigation','Main Navigation')?></span>
      <a href="/dashboard.php" class="md-drawer-link"><?=t($t,'dashboard','Dashboard')?></a>
      <a href="/submit_assessment.php" class="md-drawer-link"><?=t($t,'submit_assessment','Submit Assessment')?></a>
      <a href="/my_performance.php" class="md-drawer-link"><?=t($t,'my_performance','My Performance')?></a>
      <a href="/profile.php" class="md-drawer-link"><?=t($t,'profile','Profile')?></a>
      <?php if (in_array($_SESSION['user']['role'] ?? '', ['admin','supervisor'], true)): ?>
        <a href="/admin/supervisor_review.php" class="md-drawer-link"><?=t($t,'review_queue','Review Queue')?></a>
      <?php endif; ?>
    </div>
    <?php if (($_SESSION['user']['role'] ?? '')==='admin'): ?>
      <div class="md-drawer-section">
        <span class="md-drawer-label"><?=t($t,'admin_navigation','Administration')?></span>
        <a href="/admin/dashboard.php" class="md-drawer-link"><?=t($t,'admin_dashboard','Admin Dashboard')?></a>
        <a href="/admin/users.php" class="md-drawer-link"><?=t($t,'manage_users','Manage Users')?></a>
        <a href="/admin/questionnaire_manage.php" class="md-drawer-link"><?=t($t,'manage_questionnaires','Manage Questionnaires')?></a>
        <a href="/admin/export.php" class="md-drawer-link"><?=t($t,'export_data','Export Data')?></a>
        <a href="/admin/branding.php" class="md-drawer-link"><?=t($t,'branding','Branding & Landing')?></a>
      </div>
    <?php endif; ?>
  </nav>
</aside>
<main class="md-main">