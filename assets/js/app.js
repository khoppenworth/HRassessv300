(function() {
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
    manifest.href = '/manifest.webmanifest';
    document.head.appendChild(manifest);
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/service-worker.js').catch(() => {
        // Ignore registration failures silently
      });
    });
  }
})();
