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
    manifest.href = normalizedBase + '/manifest.php';
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
    const rootStyles = window.getComputedStyle(document.documentElement);
    const themePrimary = (rootStyles.getPropertyValue('--app-primary') || rootStyles.getPropertyValue('--brand-primary') || '').trim();
    const defaultColor = (picker.dataset.defaultColor || themePrimary || '').toUpperCase();

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

  const availableLocales = Array.isArray(window.APP_AVAILABLE_LOCALES) ? window.APP_AVAILABLE_LOCALES : [];
  const localeMap = availableLocales.reduce((acc, loc) => {
    const key = (loc || '').toString().toLowerCase();
    if (key) {
      acc[key] = key;
    }
    return acc;
  }, {});
  if (!localeMap.en) {
    localeMap.en = 'en';
  }
  const defaultLocale = (window.APP_DEFAULT_LOCALE || 'en').toString().toLowerCase();
  const currentLocale = (document.documentElement.getAttribute('lang') || defaultLocale).toLowerCase();

  const ensureTranslateElement = (callback) => {
    if (typeof callback !== 'function') {
      return;
    }
    if (window.__googleTranslateReady && window.__googleTranslateElement) {
      callback(window.__googleTranslateElement);
      return;
    }
    window.__googleTranslateCallbacks = window.__googleTranslateCallbacks || [];
    window.__googleTranslateCallbacks.push(callback);
    if (window.__googleTranslateLoading) {
      return;
    }
    window.__googleTranslateLoading = true;
    if (!document.getElementById('google_translate_element')) {
      const hidden = document.createElement('div');
      hidden.id = 'google_translate_element';
      hidden.className = 'visually-hidden';
      hidden.setAttribute('aria-hidden', 'true');
      document.body.appendChild(hidden);
    }
    window.googleTranslateElementInit = function googleTranslateElementInit() {
      window.__googleTranslateElement = new window.google.translate.TranslateElement({
        pageLanguage: defaultLocale,
        autoDisplay: false,
      }, 'google_translate_element');
      window.__googleTranslateReady = true;
      const queue = window.__googleTranslateCallbacks || [];
      while (queue.length) {
        const cb = queue.shift();
        try {
          cb(window.__googleTranslateElement);
        } catch (err) {
          // Ignore callback errors so later handlers still run.
        }
      }
    };
    const script = document.createElement('script');
    script.src = 'https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit';
    script.async = true;
    script.defer = true;
    script.onerror = () => {
      window.__googleTranslateLoading = false;
      window.__googleTranslateCallbacks = [];
    };
    document.head.appendChild(script);
  };

  const applyGoogleTranslate = (targetLocale) => {
    const locale = (targetLocale || '').toLowerCase();
    if (!locale || locale === defaultLocale) {
      return;
    }
    ensureTranslateElement(() => {
      const select = document.querySelector('#google_translate_element select');
      if (!select) {
        return;
      }
      const desiredValue = `${defaultLocale}|${locale}`;
      let optionValue = null;
      for (let i = 0; i < select.options.length; i += 1) {
        const value = select.options[i].value;
        if (value === desiredValue || value.slice(-locale.length - 1) === `|${locale}`) {
          optionValue = value;
          break;
        }
      }
      if (optionValue) {
        select.value = optionValue;
        const evt = document.createEvent('HTMLEvents');
        evt.initEvent('change', true, true);
        select.dispatchEvent(evt);
      }
    });
  };

  const storePendingLocale = (value) => {
    if (!window.sessionStorage) {
      return;
    }
    if (value) {
      window.sessionStorage.setItem('pendingTranslateLocale', value);
    } else {
      window.sessionStorage.removeItem('pendingTranslateLocale');
    }
  };

  const langLinkSelector = '.md-lang-switch a, .lang-switch a';
  document.querySelectorAll(langLinkSelector).forEach((link) => {
    link.addEventListener('click', () => {
      let targetLocale = '';
      try {
        const url = new URL(link.href, window.location.href);
        targetLocale = (url.searchParams.get('lang') || '').toLowerCase();
      } catch (err) {
        targetLocale = (link.getAttribute('data-lang') || '').toLowerCase();
      }
      if (targetLocale && localeMap[targetLocale] && targetLocale !== defaultLocale) {
        storePendingLocale(targetLocale);
      } else {
        storePendingLocale('');
      }
    });
  });

  let pendingLocale = '';
  if (window.sessionStorage) {
    pendingLocale = (window.sessionStorage.getItem('pendingTranslateLocale') || '').toLowerCase();
    if (pendingLocale) {
      window.sessionStorage.removeItem('pendingTranslateLocale');
    }
  }
  const initialLocale = pendingLocale || ((localeMap[currentLocale] && currentLocale !== defaultLocale) ? currentLocale : '');
  if (initialLocale) {
    applyGoogleTranslate(initialLocale);
  }
})();
