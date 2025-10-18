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
  window.APP_USER = <?=json_encode([
      'username' => $user['username'] ?? null,
      'full_name' => $user['full_name'] ?? null,
      'role' => $role,
  ], JSON_THROW_ON_ERROR)?>;
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
    <button
      type="button"
      class="md-status-indicator"
      data-status-indicator
      data-online-text="<?=htmlspecialchars(t($t, 'status_online', 'Online'), ENT_QUOTES, 'UTF-8')?>"
      data-offline-text="<?=htmlspecialchars(t($t, 'status_offline', 'Offline'), ENT_QUOTES, 'UTF-8')?>"
      role="switch"
      aria-live="polite"
      aria-atomic="true"
      aria-checked="true"
      data-status="online"
      title="<?=htmlspecialchars(t($t, 'toggle_offline_mode', 'Toggle offline mode'), ENT_QUOTES, 'UTF-8')?>"
    >
      <span class="md-status-dot" aria-hidden="true"></span>
      <span class="md-status-label"><?=htmlspecialchars(t($t, 'status_online', 'Online'), ENT_QUOTES, 'UTF-8')?></span>
    </button>
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
  (function () {
    var globalConnectivity = (function (existing) {
      if (existing && typeof existing === 'object') {
        return existing;
      }

      var listeners = [];
      var storageKey = 'hrassess:connectivity:forcedOffline';
      var forcedOffline = false;

      try {
        var stored = window.localStorage.getItem(storageKey);
        forcedOffline = stored === '1';
      } catch (err) {
        forcedOffline = false;
      }

      var computeOnline = function () {
        return !forcedOffline && navigator.onLine;
      };

      var notify = function () {
        var state = { online: computeOnline(), forcedOffline: forcedOffline };
        listeners.slice().forEach(function (listener) {
          try {
            listener(state);
          } catch (err) {
            // Ignore listener errors to avoid breaking other handlers.
          }
        });
        try {
          document.dispatchEvent(new CustomEvent('app:connectivity-change', { detail: state }));
        } catch (err) {
          // Ignore dispatch errors if CustomEvent is unavailable.
        }
        return state;
      };

      var persistForcedState = function () {
        try {
          window.localStorage.setItem(storageKey, forcedOffline ? '1' : '0');
        } catch (err) {
          // Ignore persistence failures (private mode, quota, etc.).
        }
      };

      var handleBrowserChange = function () {
        notify();
      };

      window.addEventListener('online', handleBrowserChange);
      window.addEventListener('offline', handleBrowserChange);

      var api = {
        isOnline: function () {
          return computeOnline();
        },
        isForcedOffline: function () {
          return forcedOffline;
        },
        setForcedOffline: function (value) {
          var next = Boolean(value);
          if (next === forcedOffline) {
            notify();
            return;
          }
          forcedOffline = next;
          persistForcedState();
          notify();
        },
        toggleForcedOffline: function () {
          api.setForcedOffline(!forcedOffline);
        },
        subscribe: function (listener) {
          if (typeof listener !== 'function') {
            return function () {};
          }
          if (!listeners.includes(listener)) {
            listeners.push(listener);
          }
          try {
            listener({ online: computeOnline(), forcedOffline: forcedOffline });
          } catch (err) {
            // Ignore listener errors during initial sync.
          }
          return function () {
            listeners = listeners.filter(function (fn) { return fn !== listener; });
          };
        },
        getState: function () {
          return { online: computeOnline(), forcedOffline: forcedOffline };
        }
      };

      notify();

      return api;
    })(window.AppConnectivity);

    window.AppConnectivity = globalConnectivity;

    var onReady = function () {
      var indicator = document.querySelector('[data-status-indicator]');
      if (indicator) {
        var label = indicator.querySelector('.md-status-label');
        var onlineText = indicator.getAttribute('data-online-text') || 'Online';
        var offlineText = indicator.getAttribute('data-offline-text') || 'Offline';

        var applyState = function (state) {
          var isOnline = state && typeof state.online === 'boolean' ? state.online : globalConnectivity.isOnline();
          var forced = state && typeof state.forcedOffline === 'boolean' ? state.forcedOffline : globalConnectivity.isForcedOffline();
          indicator.classList.toggle('is-offline', !isOnline);
          indicator.setAttribute('data-status', isOnline ? 'online' : 'offline');
          indicator.setAttribute('aria-checked', isOnline ? 'true' : 'false');
          if (forced) {
            indicator.setAttribute('data-mode', 'manual');
          } else {
            indicator.removeAttribute('data-mode');
          }
          if (label) {
            label.textContent = isOnline ? onlineText : offlineText;
          }
        };

        if (globalConnectivity && typeof globalConnectivity.subscribe === 'function') {
          globalConnectivity.subscribe(applyState);
        } else {
          var updateStatus = function () {
            applyState({ online: navigator.onLine, forcedOffline: false });
          };
          window.addEventListener('online', updateStatus);
          window.addEventListener('offline', updateStatus);
          updateStatus();
        }

        indicator.addEventListener('click', function () {
          if (globalConnectivity && typeof globalConnectivity.toggleForcedOffline === 'function') {
            globalConnectivity.toggleForcedOffline();
          }
        });
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
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', onReady);
    } else {
      onReady();
    }
  })();
</script>
<div id="google_translate_element" class="visually-hidden" aria-hidden="true"></div>
<div class="md-shell">
<nav id="app-topnav" class="md-topnav md-elev-2" data-topnav aria-label="<?=htmlspecialchars(t($t, 'primary_navigation', 'Primary navigation'), ENT_QUOTES, 'UTF-8')?>">
  <ul class="md-topnav-list">
    <?php
    $workspaceActive = $isActiveNav('workspace.my_performance', 'workspace.submit_assessment');
    ?>
    <li class="md-topnav-item<?=$workspaceActive ? ' is-active' : ''?>" data-topnav-item>
      <button type="button" class="md-topnav-trigger" data-topnav-trigger aria-haspopup="true" aria-expanded="false">
        <span><?=t($t, 'my_workspace', 'My Workspace')?></span>
        <span class="md-topnav-chevron" aria-hidden="true"></span>
      </button>
      <ul class="md-topnav-submenu">
        <li><a href="<?=htmlspecialchars(url_for('my_performance.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.my_performance')?>><?=t($t, 'my_performance', 'My Performance')?></a></li>
        <li><a href="<?=htmlspecialchars(url_for('submit_assessment.php'), ENT_QUOTES, 'UTF-8')?>" <?=$topNavLinkAttributes('workspace.submit_assessment')?>><?=t($t, 'submit_assessment', 'Submit Assessment')?></a></li>
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
<div class="md-topnav-backdrop" data-topnav-backdrop aria-hidden="true" hidden></div>
<main class="md-main">

