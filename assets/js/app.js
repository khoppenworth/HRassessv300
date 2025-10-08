(function() {
  const baseMeta = document.querySelector('meta[name="app-base-url"]');
  let appBase = window.APP_BASE_URL || (baseMeta ? baseMeta.content : '/');
  if (typeof appBase !== 'string' || appBase === '') {
    appBase = '/';
  }
  const normalizedBase = appBase.replace(/\/+$/, '') || '';

  const drawer = document.querySelector('[data-drawer]');
  const toggle = document.querySelector('[data-drawer-toggle]');
  if (drawer && toggle) {
    toggle.addEventListener('click', () => {
      drawer.classList.toggle('open');
    });
    drawer.addEventListener('click', (evt) => {
      if (evt.target.classList.contains('md-drawer-link')) {
        drawer.classList.remove('open');
      }
    });
  }

  if (!document.querySelector('link[rel="manifest"]')) {
    const manifest = document.createElement('link');
    manifest.rel = 'manifest';
    manifest.href = normalizedBase + '/manifest.webmanifest';
    document.head.appendChild(manifest);
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register(normalizedBase + '/service-worker.js', { scope: normalizedBase + '/' }).catch(() => {
        // Ignore registration failures silently
      });
    });
  }
})();
