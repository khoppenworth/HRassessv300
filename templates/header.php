<?php
require_once __DIR__ . '/../config.php';

$locale = ensure_locale();
$t = load_lang($locale);
$cfg = get_site_config($pdo);
$user = current_user();
$role = $user['role'] ?? ($_SESSION['user']['role'] ?? null);
$logoUrl = site_logo_url($cfg);
$logoPathSmall = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
$siteTitle = htmlspecialchars($cfg['site_name'] ?? 'My Performance');
$siteLogoAlt = htmlspecialchars($cfg['site_name'] ?? 'Logo', ENT_QUOTES, 'UTF-8');
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
$topNavLinkAttributes = static function (string ...$keys) use ($isActiveNav): string {
    $class = 'md-topnav-link' . ($isActiveNav(...$keys) ? ' active' : '');
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
  <button class="md-appbar-toggle" aria-label="Toggle navigation" data-drawer-toggle aria-controls="app-topnav" aria-expanded="false">
    <span></span>
    <span></span>
    <span></span>
  </button>
  <div class="md-appbar-title">
    <img src="<?=$logoPathSmall?>" alt="<?=$siteLogoAlt?>" class="md-appbar-logo" loading="lazy">
    <span><?=$siteTitle?></span>
  </div>
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
<nav id="app-topnav" class="md-topnav md-elev-2" data-topnav aria-label="<?=htmlspecialchars(t($t, 'primary_navigation', 'Primary navigation'), ENT_QUOTES, 'UTF-8')?>">
  <ul class="md-topnav-list">
    <?php
    $workspaceActive = $isActiveNav('workspace.my_performance', 'workspace.submit_assessment', 'workspace.profile');
    ?>
    <li class="md-topnav-item<?=$workspaceActive ? ' is-active' : ''?>" data-topnav-item>
      <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
        <span><?=t($t, 'my_workspace', 'My Workspace')?></span>
        <span class="md-topnav-chevron" aria-hidden="true"></span>
      </button>
      <ul class="md-topnav-submenu">
        <li><a href="<?=htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.my_performance')?>><?=t($t, 'my_performance', 'My Performance')?></a></li>
        <li><a href="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.submit_assessment')?>><?=t($t, 'submit_assessment', 'Submit Assessment')?></a></li>
        <li><a href="<?=htmlspecialchars(url_for('profile.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.profile')?>><?=t($t, 'profile', 'Profile')?></a></li>
      </ul>
    </li>
    <?php if (in_array($role, ['admin', 'supervisor'], true)): ?>
      <?php $teamActive = $isActiveNav('team.review_queue', 'team.pending_accounts', 'team.assignments'); ?>
      <li class="md-topnav-item<?=$teamActive ? ' is-active' : ''?>" data-topnav-item>
        <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
          <span><?=t($t, 'team_navigation', 'Team & Reviews')?></span>
          <span class="md-topnav-chevron" aria-hidden="true"></span>
        </button>
        <ul class="md-topnav-submenu">
          <li><a href="<?=htmlspecialchars(url_for('admin/supervisor_review.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('team.review_queue')?>><?=t($t, 'review_queue', 'Review Queue')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/pending_accounts.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('team.pending_accounts')?>><?=t($t, 'pending_accounts', 'Pending Approvals')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/questionnaire_assignments.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('team.assignments')?>><?=t($t, 'assign_questionnaires', 'Assign Questionnaires')?></a></li>
        </ul>
      </li>
    <?php endif; ?>
    <?php if ($role === 'admin'): ?>
      <?php $adminActive = $isActiveNav('admin.dashboard', 'admin.users', 'admin.manage_questionnaires', 'admin.analytics', 'admin.export', 'admin.branding', 'admin.settings'); ?>
      <li class="md-topnav-item<?=$adminActive ? ' is-active' : ''?>" data-topnav-item>
        <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
          <span><?=t($t, 'admin_navigation', 'Administration')?></span>
          <span class="md-topnav-chevron" aria-hidden="true"></span>
        </button>
        <ul class="md-topnav-submenu">
          <li><a href="<?=htmlspecialchars(url_for('admin/dashboard.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.dashboard')?>><?=t($t, 'admin_dashboard', 'Admin Dashboard')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/users.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.users')?>><?=t($t, 'manage_users', 'Manage Users')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/questionnaire_manage.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.manage_questionnaires')?>><?=t($t, 'manage_questionnaires', 'Manage Questionnaires')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/analytics.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.analytics')?>><?=t($t, 'analytics', 'Analytics')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/export.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.export')?>><?=t($t, 'export_data', 'Export Data')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/branding.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.branding')?>><?=t($t, 'branding', 'Branding & Landing')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('admin/settings.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('admin.settings')?>><?=t($t, 'settings', 'Settings')?></a></li>
          <li><a href="<?=htmlspecialchars(url_for('swagger.php'), ENT_QUOTES, 'UTF-8')?>" class="md-topnav-link" target="_blank" rel="noopener"><?=t($t,'api_documentation','API Documentation')?></a></li>
        </ul>
      </li>
    <?php endif; ?>
  </ul>
</nav>
<main class="md-main">

