<?php
require_once __DIR__ . '/../config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
$logoPath = get_branding_logo_path($cfg);
if ($logoPath === null) {
    $logoUrl = asset_url('logo.php');
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
$drawerKey = $drawerKey ?? null;
$scriptName = ltrim((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$navKeyMap = [
    'my_performance.php' => 'workspace.my_performance',
    'submit_assessment.php' => 'workspace.submit_assessment',
    'profile.php' => 'workspace.profile',
    'admin/supervisor_review.php' => 'team.review_queue',
    'admin/pending_accounts.php' => 'team.pending_accounts',
    'admin/questionnaire_assignments.php' => 'team.assignments',
    'admin/dashboard.php' => 'admin.dashboard',
    'admin/users.php' => 'admin.users',
    'admin/questionnaire_manage.php' => 'admin.manage_questionnaires',
    'admin/analytics.php' => 'admin.analytics',
    'admin/export.php' => 'admin.export',
    'admin/branding.php' => 'admin.branding',
    'admin/settings.php' => 'admin.settings',
    'swagger.php' => 'admin.api_docs',
];
if ($drawerKey === null && $scriptName !== '') {
    $drawerKey = $navKeyMap[$scriptName] ?? null;
}
$isActiveNav = static function (string ...$keys) use ($drawerKey): bool {
    if ($drawerKey === null) {
        return false;
    }
    foreach ($keys as $key) {
        if ($drawerKey === $key) {
            return true;
        }
    }
    return false;
};
$drawerLinkAttributes = static function (string ...$keys) use ($isActiveNav): string {
    $class = 'md-drawer-link' . ($isActiveNav(...$keys) ? ' active' : '');
    $aria = $isActiveNav(...$keys) ? ' aria-current="page"' : '';
    return sprintf('class="%s"%s', $class, $aria);
};
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
    <button
      type="button"
      class="md-appbar-button"
      id="appbar-install-btn"
      hidden
      aria-hidden="true"
    >
      <?=htmlspecialchars(t($t, 'install_app', 'Install App'), ENT_QUOTES, 'UTF-8')?>
    </button>
    <div
      class="md-status-indicator"
      data-status-indicator
      data-online-text="<?=htmlspecialchars(t($t, 'status_online', 'Online'), ENT_QUOTES, 'UTF-8')?>"
      data-offline-text="<?=htmlspecialchars(t($t, 'status_offline', 'Offline'), ENT_QUOTES, 'UTF-8')?>"
      role="status"
      aria-live="polite"
      aria-atomic="true"
    >
      <span class="md-status-dot" aria-hidden="true"></span>
      <span class="md-status-label"><?=htmlspecialchars(t($t, 'status_online', 'Online'), ENT_QUOTES, 'UTF-8')?></span>
    </div>
    <button type="button" class="md-appbar-button" id="appbar-reload-btn">
      <?=htmlspecialchars(t($t, 'reload_app', 'Reload App'), ENT_QUOTES, 'UTF-8')?>
    </button>
    <div class="md-lang-switch">
      <?php foreach ($availableLocales as $loc): ?>
        <a href="<?=htmlspecialchars(url_for('set_lang.php?lang=' . $loc), ENT_QUOTES, 'UTF-8')?>" class="<?=($locale === $loc) ? 'active' : ''?>"><?=strtoupper($loc)?></a>
      <?php endforeach; ?>
    </div>
    <a href="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" class="md-appbar-link"><?=htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Profile')?></a>
    <a href="<?=htmlspecialchars(url_for('logout.php'), ENT_QUOTES, 'UTF-8')?>" class="md-appbar-link"><?=t($t, 'logout', 'Logout')?></a>
  </nav>
</header>
<script nonce="<?=htmlspecialchars(csp_nonce(), ENT_QUOTES, 'UTF-8')?>">
  document.addEventListener('DOMContentLoaded', function () {
    var indicator = document.querySelector('[data-status-indicator]');
    if (indicator) {
      var label = indicator.querySelector('.md-status-label');
      var onlineText = indicator.getAttribute('data-online-text') || 'Online';
      var offlineText = indicator.getAttribute('data-offline-text') || 'Offline';

      var updateStatus = function () {
        var isOnline = navigator.onLine;
        indicator.classList.toggle('is-offline', !isOnline);
        indicator.setAttribute('data-status', isOnline ? 'online' : 'offline');
        label.textContent = isOnline ? onlineText : offlineText;
      };

      window.addEventListener('online', updateStatus);
      window.addEventListener('offline', updateStatus);
      updateStatus();
    }

    var reloadButton = document.getElementById('appbar-reload-btn');
    if (reloadButton) {
      var performReload = function () {
        window.location.reload();
      };

      reloadButton.addEventListener('click', function () {
        reloadButton.disabled = true;
        reloadButton.classList.add('is-loading');

        var cleanupTasks = [];

        if ('caches' in window && typeof caches.keys === 'function') {
          cleanupTasks.push(
            caches.keys().then(function (keys) {
              return Promise.all(keys.map(function (key) {
                return caches.delete(key);
              }));
            })
          );
        }

        if ('serviceWorker' in navigator && typeof navigator.serviceWorker.getRegistrations === 'function') {
          cleanupTasks.push(
            navigator.serviceWorker.getRegistrations().then(function (registrations) {
              return Promise.all(registrations.map(function (registration) {
                return registration.unregister();
              }));
            })
          );
        }

        if (cleanupTasks.length > 0) {
          Promise.all(cleanupTasks)
            .catch(function () { /* ignore */ })
            .finally(performReload);
        } else {
          performReload();
        }
      });
    }
  });
</script>
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
      <a href="<?=htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('workspace.my_performance')?>><?=t($t, 'my_performance', 'My Performance')?></a>
      <a href="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('workspace.submit_assessment')?>><?=t($t, 'submit_assessment', 'Submit Assessment')?></a>
      <a href="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('workspace.profile')?>><?=t($t, 'profile', 'Profile')?></a>
    </div>
    <?php if (in_array($role, ['admin', 'supervisor'], true)): ?>
      <div class="md-drawer-section">
        <span class="md-drawer-label"><?=t($t, 'team_navigation', 'Team & Reviews')?></span>
        <a href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('team.review_queue')?>><?=t($t, 'review_queue', 'Review Queue')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('team.pending_accounts')?>><?=t($t, 'pending_accounts', 'Pending Approvals')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('team.assignments')?>><?=t($t, 'assign_questionnaires', 'Assign Questionnaires')?></a>
      </div>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
      <div class="md-drawer-section">
        <span class="md-drawer-label"><?=t($t, 'admin_navigation', 'Administration')?></span>
        <a href="<?=htmlspecialchars(url_for('admin/dashboard.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.dashboard')?>><?=t($t, 'admin_dashboard', 'Admin Dashboard')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.users')?>><?=t($t, 'manage_users', 'Manage Users')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.manage_questionnaires')?>><?=t($t, 'manage_questionnaires', 'Manage Questionnaires')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.analytics')?>><?=t($t, 'analytics', 'Analytics')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/export.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.export')?>><?=t($t, 'export_data', 'Export Data')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.branding')?>><?=t($t, 'branding', 'Branding & Landing')?></a>
        <a href="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>" <?=$drawerLinkAttributes('admin.settings')?>><?=t($t, 'settings', 'Settings')?></a>
        <a href="<?=htmlspecialchars(url_for('swagger.php'), ENT_QUOTES, 'UTF-8')?>" class="md-drawer-link" target="_blank" rel="noopener"><?=t($t,'api_documentation','API Documentation')?></a>
      </div>
    <?php endif; ?>
  </nav>
</aside>
<main class="md-main">

