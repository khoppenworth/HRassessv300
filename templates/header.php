<?php
require_once __DIR__ . '/../config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
$logoPath = get_branding_logo_path($cfg);
if ($logoPath === null) {
    $logoUrl = asset_url('assets/img/epss-logo.svg');
} elseif (preg_match('#^https?://#i', $logoPath)) {
    $logoUrl = $logoPath;
} else {
    $logoUrl = asset_url(ltrim($logoPath, '/'));
}
$logoPathSmall = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
$siteTitle = htmlspecialchars($cfg['site_name'] ?? 'My Performance');
$availableLocales = available_locales();
$defaultLocale = $availableLocales[0] ?? 'en';
$brandStyle = site_brand_style($cfg);
?>
<?php if ($brandStyle !== ''): ?>
<style id="md-brand-style">:root { <?=htmlspecialchars($brandStyle, ENT_QUOTES, 'UTF-8')?>; }</style>
<?php endif; ?>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  window.APP_DEFAULT_LOCALE = <?=json_encode($defaultLocale, JSON_THROW_ON_ERROR)?>;
  window.APP_AVAILABLE_LOCALES = <?=json_encode($availableLocales, JSON_THROW_ON_ERROR)?>;
</script>
<header class="md-appbar md-elev-2">
  <button class="md-appbar-toggle" aria-label="Toggle navigation" data-drawer-toggle>
    <span></span>
    <span></span>
    <span></span>
  </button>
  <div class="md-appbar-title"><?=$siteTitle?></div>
  <nav class="md-appbar-actions">
    <div class="md-lang-switch">
      <?php foreach ($availableLocales as $loc): ?>
        <a href="<?=htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8')?>" class="<?=($locale === $loc) ? 'active' : ''?>"><?=strtoupper($loc)?></a>
      <?php endforeach; ?>
    </div>
    <a href="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" class="md-appbar-link"><?=htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Profile')?></a>
    <a href="<?=htmlspecialchars(url_for('logout.php'), ENT_QUOTES, 'UTF-8')?>" class="md-appbar-link"><?=t($t, 'logout', 'Logout')?></a>
  </nav>
</header>
<div id="google_translate_element" class="visually-hidden" aria-hidden="true"></div>
<div class="md-shell">
<aside class="md-drawer" data-drawer>
  <div class="md-drawer-header">
    <img src="<?=$logoPathSmall?>" alt="Logo" class="md-logo-sm">
    <div class="md-drawer-title"><?=$siteTitle?></div>
  </div>
  <nav class="md-drawer-nav">
    <div class="md-drawer-section">
      <span class="md-drawer-label"><?=t($t, 'my_workspace', 'My Workspace')?></span>
      <a href="<?=htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'my_performance', 'My Performance')?></a>
      <a href="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'submit_assessment', 'Submit Assessment')?></a>
      <a href="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'profile', 'Profile')?></a>
    </div>
    <?php if (in_array($role, ['admin', 'supervisor'], true)): ?>
      <div class="md-drawer-section">
        <span class="md-drawer-label"><?=t($t, 'team_navigation', 'Team & Reviews')?></span>
        <a href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'review_queue', 'Review Queue')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'pending_accounts', 'Pending Approvals')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'assign_questionnaires', 'Assign Questionnaires')?></a>
      </div>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
      <div class="md-drawer-section">
        <span class="md-drawer-label"><?=t($t, 'admin_navigation', 'Administration')?></span>
        <a href="<?=htmlspecialchars(url_for('admin/dashboard.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'admin_dashboard', 'Admin Dashboard')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'manage_users', 'Manage Users')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'manage_questionnaires', 'Manage Questionnaires')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'analytics', 'Analytics')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/export.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'export_data', 'Export Data')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'branding', 'Branding & Landing')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t, 'settings', 'Settings')?></a>
        <a href="<?=htmlspecialchars(url_for('swagger.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link"><?=t($t,'api_documentation','API Documentation')?></a>
      </div>
    <?php endif; ?>
  </nav>
</aside>
<main class="md-main">

