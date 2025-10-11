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
  const brandPickers = document.querySelectorAll('[data-brand-color-picker]');
  brandPickers.forEach((picker) => {
    const input = picker.querySelector('input[type="color"]');
    const valueEl = picker.querySelector('.md-color-value');
    const resetBtn = picker.querySelector('[data-brand-color-reset]');
    const resetField = picker.querySelector('[data-brand-color-reset-field]');
    const defaultColor = (picker.dataset.defaultColor || '#2073BF').toUpperCase();

    const formatColor = (value) => {
      if (typeof value !== 'string' || value === '') {
        return defaultColor;
      }
      return value.toUpperCase();
    };

    const updateValue = () => {
      if (input && valueEl) {
        valueEl.textContent = formatColor(input.value || defaultColor);
      }
      if (resetField) {
        resetField.value = '0';
      }
    };

    if (input) {
      input.addEventListener('input', updateValue);
      input.addEventListener('change', updateValue);
      updateValue();
    }

    if (resetBtn) {
      resetBtn.addEventListener('click', () => {
        if (input) {
          input.value = defaultColor;
        }
        if (valueEl) {
          valueEl.textContent = defaultColor;
        }
        if (resetField) {
          resetField.value = '1';
        }
      });
    }
  });

})();
