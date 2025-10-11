(() => {
  const wrappers = document.querySelectorAll('[data-phone-field]');
  wrappers.forEach((wrapper) => {
    const countrySelect = wrapper.querySelector('[data-phone-country]');
    const localInput = wrapper.querySelector('[data-phone-local]');
    const flagEl = wrapper.querySelector('[data-phone-flag]');
    const fullInput = wrapper.querySelector('[data-phone-full]');

    if (!countrySelect || !localInput) {
      return;
    }

    const sanitizeDigits = (value) => (typeof value === 'string' ? value.replace(/[^0-9]/g, '') : '');

    const updateFlag = () => {
      if (!flagEl) return;
      const option = countrySelect.options[countrySelect.selectedIndex];
      const emoji = option ? option.getAttribute('data-flag') || option.textContent.trim().slice(0, 4) : '';
      flagEl.textContent = emoji;
    };

    const updateFullValue = () => {
      const digits = sanitizeDigits(localInput.value);
      if (localInput.value !== digits) {
        const cursor = localInput.selectionStart;
        localInput.value = digits;
        if (cursor !== null) {
          localInput.setSelectionRange(Math.min(cursor, digits.length), Math.min(cursor, digits.length));
        }
      }
      if (fullInput) {
        fullInput.value = (countrySelect.value || '') + digits;
      }
    };

    countrySelect.addEventListener('change', () => {
      updateFlag();
      updateFullValue();
    });

    localInput.addEventListener('input', updateFullValue);
    localInput.addEventListener('blur', updateFullValue);

    updateFlag();
    updateFullValue();
  });
})();
