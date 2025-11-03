const Builder = (() => {
  const state = {
    questionnaires: [],
    dirty: false,
    loading: false,
    saving: false,
    csrfToken: '',
    activeKey: null,
    pendingActiveKey: null,
  };

  const selectors = {
    addButton: '#qb-add-questionnaire',
    saveButton: '#qb-save',
    publishButton: '#qb-publish',
    message: '#qb-message',
    list: '#qb-list',
    tabs: '#qb-tabs',
    sectionNav: '#qb-section-nav',
    metaCsrf: 'meta[name="csrf-token"]',
  };

  const STORAGE_KEYS = {
    active: 'hrassess:qb:last-active',
  };

  const QUESTION_TYPES = ['likert', 'choice', 'text', 'textarea', 'boolean'];
  const LIKERT_DEFAULT_LABELS = [
    '1 - Strongly Disagree',
    '2 - Disagree',
    '3 - Neutral',
    '4 - Agree',
    '5 - Strongly Agree',
  ];

  const STATUS_OPTIONS = ['draft', 'published', 'inactive'];

  function formatStatusLabel(status) {
    const normalized = String(status || '').toLowerCase();
    if (normalized === 'published') return 'Published';
    if (normalized === 'inactive') return 'Inactive';
    return 'Draft';
  }

  const STRINGS = window.QB_STRINGS || {
    scoreWeightLabel: 'Score weight (%)',
    scoreWeightHint: 'Only weighted questions contribute to scoring and analytics.',
    scoringSummaryTitle: 'Scoring summary',
    scoringSummaryManualLabel: 'Manual weight total',
    scoringSummaryEffectiveLabel: 'Effective score total',
    scoringSummaryCountLabel: 'Scorable items',
    scoringSummaryWeightedLabel: 'Items counted',
    scoringSummaryActionsLabel: 'Scoring tools',
    normalizeWeights: 'Normalize to 100%',
    evenWeights: 'Split evenly',
    clearWeights: 'Clear weights',
    likertAutoNote: 'Likert questions automatically share 100% of the score in analytics.',
    nonLikertIgnoredNote: 'While a questionnaire contains Likert questions, other question types are excluded from scoring.',
    missingWeightsWarning: 'Dashboards will show “Not scored” unless at least one question has weight.',
    manualTotalOffWarning: 'Manual weights currently add up to %s%.',
    manualTotalOk: 'Manual weights currently add up to %s%.',
    noScorableNote: 'Add Likert or weighted questions to enable scoring.',
    normalizeSuccess: 'Weights normalized to total 100%.',
    normalizeNoop: 'Add weights to questions before normalizing.',
    evenSuccess: 'Split weights evenly across scorable questions.',
    evenNoop: 'Add scorable questions before splitting weights.',
    clearSuccess: 'Cleared all question weights.',
    clearNoop: 'No weights to clear.',
  };

  const NON_SCORABLE_TYPES = ['display', 'group', 'section'];

  const baseMeta = document.querySelector('meta[name="app-base-url"]');
  let appBase = window.APP_BASE_URL || (baseMeta ? baseMeta.content : '/');
  if (typeof appBase !== 'string' || appBase === '') {
    appBase = '/';
  }
  const normalizedBase = appBase.replace(/\/+$/, '');

  function withBase(path) {
    if (!path.startsWith('/')) {
      path = '/' + path;
    }
    return normalizedBase + path;
  }

  function init() {
    const meta = document.querySelector(selectors.metaCsrf);
    if (!meta) return;
    state.csrfToken = meta.getAttribute('content') || '';

    const addBtn = document.querySelector(selectors.addButton);
    const saveBtn = document.querySelector(selectors.saveButton);
    const publishBtn = document.querySelector(selectors.publishButton);
    const tabs = document.querySelector(selectors.tabs);
    const sectionNav = document.querySelector(selectors.sectionNav);

    if (!addBtn || !saveBtn || !publishBtn) {
      return;
    }

    addBtn.addEventListener('click', () => {
      addQuestionnaire();
    });

    saveBtn.addEventListener('click', () => saveStructure(false));
    publishBtn.addEventListener('click', () => saveStructure(true));

    const list = document.querySelector(selectors.list);
    if (list) {
      list.addEventListener('input', handleInputChange);
      list.addEventListener('change', handleInputChange);
      list.addEventListener('click', handleActionClick);
    }

    if (tabs) {
      tabs.addEventListener('click', handleTabClick);
      tabs.addEventListener('keydown', handleTabKeydown);
      tabs.setAttribute('role', 'tablist');
    }

    if (sectionNav) {
      sectionNav.addEventListener('click', handleSectionNavClick);
    }

    fetchData();
  }

  function rememberActiveKey(key) {
    if (!key) {
      return;
    }
    try {
      if (typeof sessionStorage !== 'undefined') {
        sessionStorage.setItem(STORAGE_KEYS.active, key);
      }
    } catch (error) {
      console.warn('Unable to persist questionnaire tab state', error);
    }
  }

  function uuid(prefix = 'tmp') {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return `${prefix}-${window.crypto.randomUUID()}`;
    }
    return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function createOption(label = '') {
    return {
      id: null,
      clientId: uuid('o'),
      value: label,
    };
  }

  function isOptionType(type) {
    return type === 'choice' || type === 'likert';
  }

  function createLikertOptions() {
    return LIKERT_DEFAULT_LABELS.map((label) => ({
      id: null,
      clientId: uuid('o'),
      value: label,
    }));
  }

  function ensureLikertOptions(options) {
    const normalized = Array.isArray(options) ? options : [];
    return LIKERT_DEFAULT_LABELS.map((label, index) => {
      const existing = normalized[index] || {};
      const value = typeof existing.value === 'string' && existing.value.trim() !== ''
        ? existing.value
        : label;
      return {
        id: existing.id ?? null,
        clientId: existing.clientId || uuid('o'),
        value,
      };
    });
  }

  function isScorableType(type) {
    if (!type) return true;
    return !NON_SCORABLE_TYPES.includes(String(type).toLowerCase());
  }

  function isScorableItem(item) {
    if (!item) return false;
    return isScorableType(item.type);
  }

  function weightKeyForItem(item) {
    if (!item) return '';
    const linkId = typeof item.linkId === 'string' ? item.linkId.trim() : '';
    if (linkId) {
      return linkId;
    }
    if (item.id) {
      return `__id:${item.id}`;
    }
    if (item.questionnaire_item_id) {
      return `__qid:${item.questionnaire_item_id}`;
    }
    if (item.clientId) {
      return `__client:${item.clientId}`;
    }
    return '';
  }

  function evenLikertWeights(items, totalWeight = 100) {
    const keys = new Set();
    items.forEach((item) => {
      if (!item) return;
      const type = String(item.type || '').toLowerCase();
      if (type !== 'likert') return;
      const key = weightKeyForItem(item);
      if (key) {
        keys.add(key);
      }
    });
    if (!keys.size) {
      return {};
    }
    const count = keys.size;
    if (!count) {
      return {};
    }
    const evenWeight = totalWeight / count;
    const weights = {};
    keys.forEach((key) => {
      weights[key] = evenWeight;
    });
    return weights;
  }

  function resolveEffectiveWeight(item, likertWeights, isScorable = true) {
    if (!isScorable || !item) {
      return 0;
    }
    const type = String(item.type || '').toLowerCase();
    const key = weightKeyForItem(item);
    if (type === 'likert' && key && Object.prototype.hasOwnProperty.call(likertWeights, key)) {
      const mapped = Number(likertWeights[key]);
      return Number.isFinite(mapped) ? mapped : 0;
    }
    if (likertWeights && Object.keys(likertWeights).length && type !== 'likert') {
      return 0;
    }
    const fields = ['weight_percent', 'weight'];
    for (let i = 0; i < fields.length; i += 1) {
      const field = fields[i];
      if (Object.prototype.hasOwnProperty.call(item, field)) {
        const raw = Number(item[field]);
        if (Number.isFinite(raw) && raw > 0) {
          return raw;
        }
      }
    }
    return 1;
  }

  function collectQuestionnaireItems(questionnaire) {
    const entries = [];
    if (!questionnaire) {
      return entries;
    }
    const rootItems = Array.isArray(questionnaire.items) ? questionnaire.items : [];
    rootItems.forEach((item, index) => {
      if (item && item.is_active !== false) {
        entries.push({ item, sectionIndex: 'root', itemIndex: index });
      }
    });
    const sections = Array.isArray(questionnaire.sections) ? questionnaire.sections : [];
    sections.forEach((section, sectionIndex) => {
      if (section && section.is_active === false) {
        return;
      }
      const list = Array.isArray(section.items) ? section.items : [];
      list.forEach((item, itemIndex) => {
        if (item && item.is_active !== false) {
          entries.push({ item, sectionIndex, itemIndex, section });
        }
      });
    });
    return entries;
  }

  function formatPercent(value, includeSymbol = true) {
    const numeric = Number(value) || 0;
    const delta = Math.abs(Math.round(numeric) - numeric);
    const decimals = delta < 0.01 ? 0 : 1;
    const formatted = numeric.toLocaleString(undefined, {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
    return includeSymbol ? `${formatted}%` : formatted;
  }

  function computeQuestionnaireScoring(questionnaire, qIndex = null) {
    const entries = collectQuestionnaireItems(questionnaire);
    const scorable = entries.map((entry) => entry.item).filter((item) => isScorableItem(item));
    const likertWeights = evenLikertWeights(scorable);
    let totalManual = 0;
    let totalEffective = 0;
    let scorableCount = 0;
    let weightedCount = 0;
    let manualWeightedCount = 0;
    let likertCount = 0;
    let nonLikertCount = 0;
    let manualNonLikertTotal = 0;
    let hasAnyWeight = false;
    const effectiveByKey = {};

    scorable.forEach((item) => {
      scorableCount += 1;
      const key = weightKeyForItem(item);
      const type = String(item.type || '').toLowerCase();
      if (type === 'likert') {
        likertCount += 1;
      } else {
        nonLikertCount += 1;
      }
      const manualWeight = Number(item.weight_percent) || 0;
      if (manualWeight) {
        hasAnyWeight = true;
      }
      if (type !== 'likert') {
        manualNonLikertTotal += manualWeight > 0 ? manualWeight : 0;
      }
      if (manualWeight > 0) {
        manualWeightedCount += 1;
      }
      const effective = resolveEffectiveWeight(item, likertWeights, true);
      if (effective > 0) {
        weightedCount += 1;
      }
      totalManual += manualWeight > 0 ? manualWeight : 0;
      totalEffective += effective > 0 ? effective : 0;
      if (key) {
        effectiveByKey[key] = {
          effective,
          manual: manualWeight,
          type,
        };
      }
    });

    const messages = [];
    if (!scorableCount) {
      messages.push({ type: 'warning', text: STRINGS.noScorableNote });
    }
    if (likertCount) {
      messages.push({ type: 'info', text: STRINGS.likertAutoNote });
    }
    if (likertCount && nonLikertCount) {
      messages.push({ type: 'warning', text: STRINGS.nonLikertIgnoredNote });
    }
    if (scorableCount && weightedCount === 0) {
      messages.push({ type: 'warning', text: STRINGS.missingWeightsWarning });
    }
    if (!likertCount && nonLikertCount && manualNonLikertTotal > 0) {
      const manualValue = formatPercent(totalManual, false);
      const delta = Math.abs(totalManual - 100);
      const template = delta <= 1 ? STRINGS.manualTotalOk : STRINGS.manualTotalOffWarning;
      if (template && template.includes('%s')) {
        const rendered = template.replace('%s', manualValue).replace(/%%/g, '%');
        messages.push({
          type: delta <= 1 ? 'info' : 'warning',
          text: rendered,
        });
      }
    }

    return {
      qIndex,
      scorableCount,
      weightedCount,
      manualWeightedCount,
      totalManual,
      totalEffective,
      likertCount,
      nonLikertCount,
      manualNonLikertTotal,
      hasLikert: likertCount > 0,
      hasAnyWeight,
      canNormalize: nonLikertCount > 0 && !likertCount && manualNonLikertTotal > 0,
      canDistribute: nonLikertCount > 0 && !likertCount,
      canClear: hasAnyWeight,
      messages,
      effectiveByKey,
    };
  }

  function updateScoringSummaryElement(container, summary) {
    if (!container || !summary) {
      return;
    }
    if (summary.qIndex !== null && typeof summary.qIndex !== 'undefined') {
      container.dataset.qIndex = String(summary.qIndex);
    }
    const manualEl = container.querySelector('[data-metric="manual-total"]');
    if (manualEl) {
      manualEl.textContent = formatPercent(summary.totalManual);
    }
    const effectiveEl = container.querySelector('[data-metric="effective-total"]');
    if (effectiveEl) {
      effectiveEl.textContent = formatPercent(summary.totalEffective);
    }
    const scorableEl = container.querySelector('[data-metric="scorable-count"]');
    if (scorableEl) {
      scorableEl.textContent = String(summary.scorableCount);
    }
    const weightedEl = container.querySelector('[data-metric="weighted-count"]');
    if (weightedEl) {
      if (summary.scorableCount) {
        weightedEl.textContent = `${summary.weightedCount} / ${summary.scorableCount}`;
      } else {
        weightedEl.textContent = String(summary.weightedCount);
      }
    }

    const messagesEl = container.querySelector('[data-role="scoring-messages"]');
    if (messagesEl) {
      messagesEl.innerHTML = '';
      (summary.messages || []).forEach((message) => {
        if (!message || !message.text) {
          return;
        }
        const item = document.createElement('li');
        item.className = 'qb-scoring-message';
        item.dataset.type = message.type || 'info';
        item.textContent = message.text;
        messagesEl.appendChild(item);
      });
      messagesEl.hidden = !(summary.messages && summary.messages.length);
    }

    const actionsButtons = container.querySelector('[data-role="scoring-actions-buttons"]');
    const actionsWrapper = container.querySelector('.qb-scoring-actions');
    if (actionsButtons) {
      actionsButtons.innerHTML = '';
      actionsButtons.dataset.qIndex = summary.qIndex !== null && summary.qIndex !== undefined
        ? String(summary.qIndex)
        : '';
      const createButton = (label, action) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'md-button md-outline qb-action';
        btn.textContent = label;
        btn.dataset.action = action;
        if (summary.qIndex !== null && summary.qIndex !== undefined) {
          btn.dataset.qIndex = String(summary.qIndex);
        }
        return btn;
      };
      if (summary.canNormalize) {
        actionsButtons.appendChild(createButton(STRINGS.normalizeWeights, 'normalize-weights'));
      }
      if (summary.canDistribute) {
        actionsButtons.appendChild(createButton(STRINGS.evenWeights, 'even-weights'));
      }
      if (summary.canClear) {
        actionsButtons.appendChild(createButton(STRINGS.clearWeights, 'clear-weights'));
      }
    }
    if (actionsWrapper) {
      const hasButtons = actionsButtons && actionsButtons.childElementCount > 0;
      actionsWrapper.hidden = !hasButtons;
    }
  }

  function buildScoringSummary(summary) {
    const container = document.createElement('div');
    container.className = 'qb-scoring-summary';
    container.dataset.role = 'scoring-summary';
    if (summary.qIndex !== null && summary.qIndex !== undefined) {
      container.dataset.qIndex = String(summary.qIndex);
    }

    const heading = document.createElement('div');
    heading.className = 'qb-scoring-summary-heading qb-inline-heading';
    heading.textContent = STRINGS.scoringSummaryTitle;
    container.appendChild(heading);

    const metrics = document.createElement('dl');
    metrics.className = 'qb-scoring-metrics';
    const metricSpecs = [
      { label: STRINGS.scoringSummaryManualLabel, key: 'manual-total' },
      { label: STRINGS.scoringSummaryEffectiveLabel, key: 'effective-total' },
      { label: STRINGS.scoringSummaryCountLabel, key: 'scorable-count' },
      { label: STRINGS.scoringSummaryWeightedLabel, key: 'weighted-count' },
    ];
    metricSpecs.forEach((spec) => {
      const wrapper = document.createElement('div');
      wrapper.className = 'qb-scoring-metric';
      const label = document.createElement('dt');
      label.textContent = spec.label;
      const value = document.createElement('dd');
      value.dataset.metric = spec.key;
      value.textContent = '0';
      wrapper.appendChild(label);
      wrapper.appendChild(value);
      metrics.appendChild(wrapper);
    });
    container.appendChild(metrics);

    const messageList = document.createElement('ul');
    messageList.className = 'qb-scoring-messages';
    messageList.dataset.role = 'scoring-messages';
    container.appendChild(messageList);

    const actionsWrapper = document.createElement('div');
    actionsWrapper.className = 'qb-scoring-actions';
    const actionsLabel = document.createElement('span');
    actionsLabel.className = 'qb-scoring-actions-label';
    actionsLabel.textContent = STRINGS.scoringSummaryActionsLabel;
    actionsWrapper.appendChild(actionsLabel);
    const actionsButtons = document.createElement('div');
    actionsButtons.className = 'qb-scoring-actions-buttons';
    actionsButtons.dataset.role = 'scoring-actions-buttons';
    actionsWrapper.appendChild(actionsButtons);
    container.appendChild(actionsWrapper);

    updateScoringSummaryElement(container, summary);
    return container;
  }

  function refreshScoringSummary(qIndex) {
    if (Number.isNaN(qIndex) || qIndex === null || qIndex < 0) {
      return;
    }
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) {
      return;
    }
    const summary = computeQuestionnaireScoring(questionnaire, qIndex);
    const card = document.querySelector(`.qb-questionnaire[data-q-index="${qIndex}"]`);
    if (!card) {
      return;
    }
    const summaryEl = card.querySelector('.qb-scoring-summary');
    if (!summaryEl) {
      return;
    }
    updateScoringSummaryElement(summaryEl, summary);
  }

  function forEachQuestionnaireItem(questionnaire, callback) {
    if (!questionnaire || typeof callback !== 'function') {
      return;
    }
    const rootItems = Array.isArray(questionnaire.items) ? questionnaire.items : [];
    rootItems.forEach((item, itemIndex) => {
      callback(item, { sectionIndex: 'root', itemIndex });
    });
    const sections = Array.isArray(questionnaire.sections) ? questionnaire.sections : [];
    sections.forEach((section, sectionIndex) => {
      const list = Array.isArray(section.items) ? section.items : [];
      list.forEach((item, itemIndex) => {
        callback(item, { section, sectionIndex, itemIndex });
      });
    });
  }

  function normalizeManualWeights(questionnaire) {
    const targets = [];
    forEachQuestionnaireItem(questionnaire, (item) => {
      if (!isScorableItem(item)) return;
      if (String(item.type || '').toLowerCase() === 'likert') return;
      const weight = Number(item.weight_percent) || 0;
      if (weight > 0) {
        targets.push({ item, weight });
      }
    });
    if (!targets.length) {
      return { changed: false };
    }
    const total = targets.reduce((sum, entry) => sum + entry.weight, 0);
    if (total <= 0) {
      return { changed: false };
    }
    const normalized = targets.map((entry) => {
      const raw = (entry.weight / total) * 100;
      const base = Math.floor(raw);
      const fraction = raw - base;
      return { ...entry, raw, base, fraction, value: base };
    });
    let assigned = normalized.reduce((sum, entry) => sum + entry.base, 0);
    let remainder = Math.round(100 - assigned);
    if (remainder !== 0 && normalized.length) {
      const adjustList = normalized.slice().sort((a, b) => (remainder > 0 ? b.fraction - a.fraction : a.fraction - b.fraction));
      const step = remainder > 0 ? 1 : -1;
      const limit = Math.abs(remainder);
      for (let i = 0; i < limit; i += 1) {
        const target = adjustList[i % adjustList.length];
        target.value += step;
      }
    }
    let changed = false;
    normalized.forEach((entry) => {
      const newWeight = Math.max(0, Math.round(entry.value));
      if ((Number(entry.item.weight_percent) || 0) !== newWeight) {
        entry.item.weight_percent = newWeight;
        changed = true;
      }
    });
    return { changed };
  }

  function evenManualWeights(questionnaire) {
    const targets = [];
    forEachQuestionnaireItem(questionnaire, (item) => {
      if (!isScorableItem(item)) return;
      if (String(item.type || '').toLowerCase() === 'likert') return;
      targets.push(item);
    });
    const count = targets.length;
    if (!count) {
      return { changed: false };
    }
    const base = Math.floor(100 / count);
    let remainder = 100 - base * count;
    let changed = false;
    targets.forEach((item) => {
      let nextValue = base;
      if (remainder > 0) {
        nextValue += 1;
        remainder -= 1;
      }
      if ((Number(item.weight_percent) || 0) !== nextValue) {
        item.weight_percent = nextValue;
        changed = true;
      }
    });
    return { changed };
  }

  function clearAllWeights(questionnaire) {
    let changed = false;
    forEachQuestionnaireItem(questionnaire, (item) => {
      if (!item) return;
      const current = Number(item.weight_percent) || 0;
      if (current !== 0) {
        item.weight_percent = 0;
        changed = true;
      }
    });
    return { changed };
  }

  function normalizeQuestionnaireWeights(qIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) return;
    const result = normalizeManualWeights(questionnaire);
    if (!result.changed) {
      setMessage(STRINGS.normalizeNoop, 'info');
      refreshScoringSummary(qIndex);
      return;
    }
    markDirty();
    render();
    setMessage(STRINGS.normalizeSuccess, 'success');
  }

  function evenQuestionnaireWeights(qIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) return;
    const result = evenManualWeights(questionnaire);
    if (!result.changed) {
      setMessage(STRINGS.evenNoop, 'info');
      refreshScoringSummary(qIndex);
      return;
    }
    markDirty();
    render();
    setMessage(STRINGS.evenSuccess, 'success');
  }

  function clearQuestionnaireWeights(qIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) return;
    const result = clearAllWeights(questionnaire);
    if (!result.changed) {
      setMessage(STRINGS.clearNoop, 'info');
      refreshScoringSummary(qIndex);
      return;
    }
    markDirty();
    render();
    setMessage(STRINGS.clearSuccess, 'success');
  }

  function handleInputChange(event) {
    const target = event.target;
    const role = target.dataset.role;
    if (!role) return;
    const qIndex = parseInt(target.dataset.qIndex ?? '-1', 10);
    if (Number.isNaN(qIndex) || !state.questionnaires[qIndex]) return;
    let requiresRender = false;

    if (role === 'q-title') {
      state.questionnaires[qIndex].title = target.value;
      updateTabLabel(qIndex);
    } else if (role === 'q-description') {
      state.questionnaires[qIndex].description = target.value;
    } else if (role === 'q-status') {
      const nextStatus = String(target.value || '').toLowerCase();
      if (['draft', 'published', 'inactive'].includes(nextStatus)) {
        state.questionnaires[qIndex].status = nextStatus;
        requiresRender = true;
      }
    } else if (role === 'section-active') {
      const sectionIndex = parseSectionIndex(target.dataset.sectionIndex);
      const section = getSection(qIndex, sectionIndex);
      if (!section) return;
      section.is_active = target.checked;
      requiresRender = true;
      renderSectionNav();
    } else if (role === 'section-title' || role === 'section-description') {
      const sectionIndex = parseSectionIndex(target.dataset.sectionIndex);
      const section = getSection(qIndex, sectionIndex);
      if (!section) return;
      if (role === 'section-title') {
        section.title = target.value;
        renderSectionNav();
      } else {
        section.description = target.value;
      }
    } else if (role.startsWith('item-')) {
      const sectionIndex = parseSectionIndex(target.dataset.sectionIndex);
      const itemIndex = parseInt(target.dataset.itemIndex ?? '-1', 10);
      const list = getItemList(qIndex, sectionIndex);
      if (!list || !list[itemIndex]) return;
      const item = list[itemIndex];
      if (role === 'item-linkId') {
        item.linkId = target.value;
      } else if (role === 'item-text') {
        item.text = target.value;
      } else if (role === 'item-type') {
        const newType = target.value;
        if (!QUESTION_TYPES.includes(newType)) {
          return;
        }
        item.type = newType;
        if (newType === 'choice') {
          item.options = Array.isArray(item.options) ? item.options : [];
          if (!item.options.length) {
            item.options.push(createOption('Option 1'));
            item.options.push(createOption('Option 2'));
          }
        } else if (newType === 'likert') {
          item.allow_multiple = false;
          item.options = ensureLikertOptions(item.options);
        } else {
          item.allow_multiple = false;
          item.options = [];
        }
        requiresRender = true;
      } else if (role === 'item-weight') {
        item.weight_percent = parseInt(target.value || '0', 10) || 0;
        refreshScoringSummary(qIndex);
      } else if (role === 'item-allow-multiple') {
        item.allow_multiple = target.checked;
      } else if (role === 'item-required') {
        item.is_required = target.checked;
      } else if (role === 'item-active') {
        item.is_active = target.checked;
        requiresRender = true;
      }
    } else if (role === 'option-value') {
      const sectionIndex = parseSectionIndex(target.dataset.sectionIndex);
      const itemIndex = parseInt(target.dataset.itemIndex ?? '-1', 10);
      const optionIndex = parseInt(target.dataset.optionIndex ?? '-1', 10);
      const options = getOptionList(qIndex, sectionIndex, itemIndex);
      if (!options || !options[optionIndex]) return;
      options[optionIndex].value = target.value;
    }
    markDirty();
    if (requiresRender) {
      render();
    }
  }

  function handleActionClick(event) {
    const button = event.target.closest('[data-action]');
    if (!button) return;
    event.preventDefault();
    const action = button.dataset.action;
    const qIndex = parseInt(button.dataset.qIndex ?? '-1', 10);
    if (Number.isNaN(qIndex) && action !== 'add-questionnaire') return;

    if (action === 'delete-questionnaire') {
      removeQuestionnaire(qIndex);
    } else if (action === 'add-section') {
      addSection(qIndex);
    } else if (action === 'delete-section') {
      const sectionIndex = parseSectionIndex(button.dataset.sectionIndex);
      removeSection(qIndex, sectionIndex);
    } else if (action === 'add-item') {
      const sectionIndex = parseSectionIndex(button.dataset.sectionIndex);
      addItem(qIndex, sectionIndex);
    } else if (action === 'delete-item') {
      const sectionIndex = parseSectionIndex(button.dataset.sectionIndex);
      const itemIndex = parseInt(button.dataset.itemIndex ?? '-1', 10);
      removeItem(qIndex, sectionIndex, itemIndex);
    } else if (action === 'add-option') {
      const sectionIndex = parseSectionIndex(button.dataset.sectionIndex);
      const itemIndex = parseInt(button.dataset.itemIndex ?? '-1', 10);
      addOption(qIndex, sectionIndex, itemIndex);
    } else if (action === 'delete-option') {
      const sectionIndex = parseSectionIndex(button.dataset.sectionIndex);
      const itemIndex = parseInt(button.dataset.itemIndex ?? '-1', 10);
      const optionIndex = parseInt(button.dataset.optionIndex ?? '-1', 10);
      removeOption(qIndex, sectionIndex, itemIndex, optionIndex);
    } else if (action === 'normalize-weights') {
      normalizeQuestionnaireWeights(qIndex);
    } else if (action === 'even-weights') {
      evenQuestionnaireWeights(qIndex);
    } else if (action === 'clear-weights') {
      clearQuestionnaireWeights(qIndex);
    }
  }

  function handleTabClick(event) {
    const tab = event.target.closest('[data-q-key]');
    if (!tab) return;
    event.preventDefault();
    const key = tab.getAttribute('data-q-key');
    if (!key) return;
    setActiveKey(key);
  }

  function handleTabKeydown(event) {
    const tab = event.target.closest('[data-q-key]');
    if (!tab) return;
    const { key } = event;
    if (key !== 'ArrowLeft' && key !== 'ArrowRight') {
      return;
    }
    const container = event.currentTarget;
    const tabs = Array.from(container.querySelectorAll('[data-q-key]'));
    const index = tabs.indexOf(tab);
    if (index === -1) return;
    event.preventDefault();
    const offset = key === 'ArrowLeft' ? -1 : 1;
    const nextIndex = (index + offset + tabs.length) % tabs.length;
    const nextTab = tabs[nextIndex];
    if (nextTab) {
      const nextKey = nextTab.getAttribute('data-q-key');
      if (nextKey) {
        setActiveKey(nextKey);
      }
      nextTab.focus();
    }
  }

  function parseSectionIndex(value) {
    if (value === 'root') return 'root';
    const parsed = parseInt(value ?? '-1', 10);
    return Number.isNaN(parsed) ? null : parsed;
  }

  function getSection(qIndex, sectionIndex) {
    if (sectionIndex === 'root' || sectionIndex === null) return null;
    return state.questionnaires[qIndex]?.sections?.[sectionIndex] ?? null;
  }

  function getItemList(qIndex, sectionIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) return null;
    if (sectionIndex === 'root' || sectionIndex === null) {
      questionnaire.items = questionnaire.items || [];
      return questionnaire.items;
    }
    questionnaire.sections = questionnaire.sections || [];
    const section = questionnaire.sections[sectionIndex];
    if (!section) return null;
    section.items = section.items || [];
    return section.items;
  }

  function getOptionList(qIndex, sectionIndex, itemIndex) {
    const items = getItemList(qIndex, sectionIndex);
    if (!items || Number.isNaN(itemIndex) || !items[itemIndex]) return null;
    const item = items[itemIndex];
    item.options = Array.isArray(item.options) ? item.options : [];
    return item.options;
  }

  function addQuestionnaire() {
    const questionnaire = {
      id: null,
      clientId: uuid('q'),
      title: 'Untitled Questionnaire',
      description: '',
      sections: [],
      items: [],
      status: 'draft',
      hasResponses: false,
      responseCount: 0,
    };
    state.questionnaires.unshift(questionnaire);
    state.activeKey = keyFor(questionnaire);
    state.pendingActiveKey = state.activeKey;
    rememberActiveKey(state.activeKey);
    markDirty();
    render();
  }

  function removeQuestionnaire(qIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (Number.isNaN(qIndex) || !questionnaire) return;
    if (questionnaire.hasResponses || (questionnaire.responseCount && questionnaire.responseCount > 0)) {
      const confirmed = window.confirm('This questionnaire has submitted responses. Mark it inactive instead of deleting?');
      if (!confirmed) {
        return;
      }
      questionnaire.status = 'inactive';
      markDirty();
      render();
      return;
    }
    if (!window.confirm('Delete this questionnaire and all of its content?')) return;
    state.questionnaires.splice(qIndex, 1);
    ensureActiveKey();
    markDirty();
    render();
  }

  function addSection(qIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) return;
    questionnaire.sections = questionnaire.sections || [];
    questionnaire.sections.push({
      id: null,
      clientId: uuid('s'),
      title: 'New Section',
      description: '',
      items: [],
      is_active: true,
      hasResponses: false,
    });
    markDirty();
    render();
  }

  function removeSection(qIndex, sectionIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire || sectionIndex === null || sectionIndex === 'root') return;
    const section = questionnaire.sections[sectionIndex];
    if (!section) return;
    const hasResponses = Boolean(section.hasResponses) || (Array.isArray(section.items) && section.items.some((item) => item.hasResponses));
      if (section.id && hasResponses) {
        const confirmed = window.confirm('This section includes questions with submitted responses. Mark it inactive?');
        if (!confirmed) {
          return;
        }
        section.is_active = false;
        section.hasResponses = true;
        markDirty();
        render();
        return;
      }
    questionnaire.sections.splice(sectionIndex, 1);
    markDirty();
    render();
  }

  function addItem(qIndex, sectionIndex) {
    const list = getItemList(qIndex, sectionIndex);
    if (!list) return;
    list.push({
      id: null,
      clientId: uuid('i'),
      linkId: `item-${list.length + 1}`,
      text: '',
      type: 'likert',
      weight_percent: 0,
      allow_multiple: false,
      is_required: false,
      options: createLikertOptions(),
      is_active: true,
      hasResponses: false,
    });
    markDirty();
    render();
  }

  function removeItem(qIndex, sectionIndex, itemIndex) {
    const list = getItemList(qIndex, sectionIndex);
    if (!list || Number.isNaN(itemIndex) || !list[itemIndex]) return;
    const item = list[itemIndex];
    if (item.id && item.hasResponses) {
      const confirmed = window.confirm('This question has submitted responses. Mark it inactive?');
      if (!confirmed) {
        return;
      }
      item.is_active = false;
      item.hasResponses = true;
      markDirty();
      render();
      return;
    }
    list.splice(itemIndex, 1);
    markDirty();
    render();
  }

  function addOption(qIndex, sectionIndex, itemIndex) {
    const items = getItemList(qIndex, sectionIndex);
    if (!items || Number.isNaN(itemIndex) || !items[itemIndex] || items[itemIndex].type !== 'choice') {
      return;
    }
    const options = getOptionList(qIndex, sectionIndex, itemIndex);
    if (!options) return;
    options.push(createOption(`Option ${options.length + 1}`));
    markDirty();
    render();
  }

  function removeOption(qIndex, sectionIndex, itemIndex, optionIndex) {
    const items = getItemList(qIndex, sectionIndex);
    if (!items || Number.isNaN(itemIndex) || !items[itemIndex] || items[itemIndex].type !== 'choice') {
      return;
    }
    const options = getOptionList(qIndex, sectionIndex, itemIndex);
    if (!options || options.length <= 1 || Number.isNaN(optionIndex) || !options[optionIndex]) return;
    options.splice(optionIndex, 1);
    markDirty();
    render();
  }

  async function fetchData(options = {}) {
    const { silent = false } = options;
    state.loading = true;
    if (!silent) {
      setMessage('Loading questionnaires...', 'info');
    }
    try {
      const response = await fetch(withBase('/admin/questionnaire_manage.php?action=fetch'), {
        headers: {
          'X-CSRF-Token': state.csrfToken,
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        cache: 'no-store',
      });
      if (!response.ok) {
        throw new Error(`Failed to load data (${response.status})`);
      }
      const data = await response.json();
      if (data.csrf) {
        updateCsrf(data.csrf);
      }
      const questionnaires = Array.isArray(data.questionnaires) ? data.questionnaires : [];
      state.questionnaires = questionnaires.map(normalizeQuestionnaire);
      let restoredActive = false;
      if (typeof window.QB_INITIAL_ACTIVE_ID !== 'undefined' && window.QB_INITIAL_ACTIVE_ID !== null) {
        const requested = Number(window.QB_INITIAL_ACTIVE_ID);
        const match = state.questionnaires.find((q) => Number(q.id) === requested);
        if (match) {
          state.activeKey = keyFor(match);
          state.pendingActiveKey = state.activeKey;
          rememberActiveKey(state.activeKey);
          restoredActive = true;
        }
        delete window.QB_INITIAL_ACTIVE_ID;
      }
      if (!restoredActive) {
        try {
          if (typeof sessionStorage !== 'undefined') {
            const storedKey = sessionStorage.getItem(STORAGE_KEYS.active);
            if (storedKey && state.questionnaires.some((q) => keyFor(q) === storedKey)) {
              state.activeKey = storedKey;
              state.pendingActiveKey = state.activeKey;
              restoredActive = true;
            }
          }
        } catch (error) {
          console.warn('Unable to restore questionnaire tab state', error);
        }
      }
      ensureActiveKey();
      state.dirty = false;
      render();
      if (!silent) {
        setMessage('Loaded questionnaires', 'success');
      }
    } catch (error) {
      console.error(error);
      setMessage(error.message || 'Failed to load questionnaires', 'error');
    } finally {
      state.loading = false;
      updateDirtyState();
    }
  }

  function normalizeQuestionnaire(q) {
    const questionnaire = {
      id: q.id ?? null,
      clientId: q.clientId || `q-${q.id ?? uuid('q')}`,
      title: q.title ?? '',
      description: q.description ?? '',
      status: typeof q.status === 'string' ? q.status.toLowerCase() : 'draft',
      sections: [],
      items: [],
      work_functions: Array.isArray(q.work_functions) ? [...q.work_functions] : undefined,
      hasResponses: Boolean(q.has_responses),
      responseCount: Number.isFinite(q.response_count) ? q.response_count : parseInt(q.response_count ?? '0', 10) || 0,
    };
    const sections = Array.isArray(q.sections) ? q.sections : [];
    questionnaire.sections = sections.map((section) => ({
      id: section.id ?? null,
      clientId: section.clientId || `s-${section.id ?? uuid('s')}`,
      title: section.title ?? '',
      description: section.description ?? '',
      items: normalizeItems(section.items),
      is_active: section.is_active !== false,
      hasResponses: Boolean(section.has_responses),
    }));
    questionnaire.items = normalizeItems(q.items);
    return questionnaire;
  }

  function normalizeItems(items) {
    if (!Array.isArray(items)) return [];
    return items.map((item) => {
      const normalizedType = QUESTION_TYPES.includes(item.type) ? item.type : 'likert';
      let normalizedOptions = [];
      if (normalizedType === 'choice') {
        normalizedOptions = normalizeOptions(item.options);
      } else if (normalizedType === 'likert') {
        normalizedOptions = ensureLikertOptions(normalizeOptions(item.options));
      }
      return {
        id: item.id ?? null,
        clientId: item.clientId || `i-${item.id ?? uuid('i')}`,
        linkId: item.linkId ?? '',
        text: item.text ?? '',
        type: normalizedType,
        weight_percent: Number.isFinite(item.weight_percent) ? item.weight_percent : parseInt(item.weight_percent || '0', 10) || 0,
        allow_multiple: normalizedType === 'choice' ? Boolean(item.allow_multiple) : false,
        is_required: Boolean(item.is_required),
        options: normalizedOptions,
        is_active: item.is_active !== false,
        hasResponses: Boolean(item.has_responses),
      };
    });
  }

  function normalizeOptions(options) {
    if (!Array.isArray(options)) return [];
    return options.map((option) => ({
      id: option.id ?? null,
      clientId: option.clientId || `o-${option.id ?? uuid('o')}`,
      value: option.value ?? '',
    }));
  }

  function serializeQuestionnaire(questionnaire, publish = false) {
    const status = typeof questionnaire.status === 'string' ? questionnaire.status.toLowerCase() : 'draft';
    const normalizedStatus = publish && status !== 'inactive'
      ? 'published'
      : status || (publish ? 'published' : 'draft');
    const payload = {
      id: questionnaire.id ?? null,
      clientId: questionnaire.clientId ?? null,
      title: questionnaire.title ?? '',
      description: questionnaire.description ?? '',
      status: normalizedStatus,
      sections: [],
      items: [],
    };
    if (Array.isArray(questionnaire.work_functions)) {
      payload.work_functions = [...questionnaire.work_functions];
    }
    payload.sections = Array.isArray(questionnaire.sections)
      ? questionnaire.sections.map((section) => serializeSection(section))
      : [];
    payload.items = serializeItems(questionnaire.items);
    return payload;
  }

  function serializeSection(section) {
    return {
      id: section.id ?? null,
      clientId: section.clientId ?? null,
      title: section.title ?? '',
      description: section.description ?? '',
      is_active: section.is_active !== false,
      items: serializeItems(section.items),
    };
  }

  function serializeItems(items) {
    if (!Array.isArray(items)) return [];
    return items.map((item) => ({
      id: item.id ?? null,
      clientId: item.clientId ?? null,
      linkId: item.linkId ?? '',
      text: item.text ?? '',
      type: QUESTION_TYPES.includes(item.type) ? item.type : 'likert',
      weight_percent: Number.isFinite(item.weight_percent)
        ? item.weight_percent
        : parseInt(item.weight_percent || '0', 10) || 0,
      allow_multiple: QUESTION_TYPES.includes(item.type) && item.type === 'choice'
        ? Boolean(item.allow_multiple)
        : false,
      is_required: Boolean(item.is_required),
      is_active: item.is_active !== false,
      options: serializeOptions(item.options),
    }));
  }

  function serializeOptions(options) {
    if (!Array.isArray(options)) return [];
    return options
      .map((option) => ({
        id: option.id ?? null,
        clientId: option.clientId ?? null,
        value: option.value ?? '',
      }))
      .filter((option) => option.value !== '');
  }

  function updateCsrf(token) {
    if (!token) return;
    state.csrfToken = token;
    const meta = document.querySelector(selectors.metaCsrf);
    if (meta) {
      meta.setAttribute('content', token);
    }
  }

  function markDirty() {
    state.dirty = true;
    updateDirtyState();
  }

  function updateDirtyState() {
    const saveBtn = document.querySelector(selectors.saveButton);
    const publishBtn = document.querySelector(selectors.publishButton);
    const disabled = state.loading || state.saving || !state.dirty;
    if (saveBtn) saveBtn.disabled = disabled;
    if (publishBtn) publishBtn.disabled = disabled;
  }

  function setMessage(message, type = 'info') {
    const el = document.querySelector(selectors.message);
    if (!el) return;
    el.textContent = message;
    el.dataset.state = type;
  }

  function keyFor(entity) {
    if (!entity) return '';
    if (entity.id) return `id:${entity.id}`;
    return `client:${entity.clientId}`;
  }

  function domIdFor(prefix, entity) {
    const key = keyFor(entity);
    const normalized = key.replace(/[^a-zA-Z0-9_-]/g, '-');
    return `${prefix}-${normalized}`;
  }

  function escapeSelector(value) {
    if (typeof value !== 'string') {
      return '';
    }
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return value.replace(/"/g, '\"');
  }

  function updateTabLabel(qIndex) {
    const tabs = document.querySelector(selectors.tabs);
    if (!tabs) return;
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire) return;
    const key = keyFor(questionnaire);
    const selector = `[data-q-key="${escapeSelector(key)}"]`;
    const tab = tabs.querySelector(selector);
    if (!tab) return;
    const label = questionnaire.title && questionnaire.title.trim() !== ''
      ? questionnaire.title
      : `Questionnaire ${qIndex + 1}`;
    tab.textContent = label;
    renderSectionNav();
  }

  function ensureActiveKey() {
    if (!state.questionnaires.length) {
      state.activeKey = null;
      try {
        if (typeof sessionStorage !== 'undefined') {
          sessionStorage.removeItem(STORAGE_KEYS.active);
        }
      } catch (error) {
        console.warn('Unable to clear questionnaire tab state', error);
      }
      return;
    }
    if (state.activeKey && state.questionnaires.some((q) => keyFor(q) === state.activeKey)) {
      return;
    }
    state.activeKey = keyFor(state.questionnaires[0]);
    state.pendingActiveKey = state.activeKey;
    rememberActiveKey(state.activeKey);
  }

  function setActiveKey(key) {
    if (!key || state.activeKey === key) {
      return;
    }
    if (!state.questionnaires.some((q) => keyFor(q) === key)) {
      return;
    }
    state.activeKey = key;
    state.pendingActiveKey = key;
    rememberActiveKey(key);
    render();
  }

  function focusActiveQuestionnaire() {
    if (!state.pendingActiveKey) {
      return;
    }
    const activeKey = state.pendingActiveKey;
    state.pendingActiveKey = null;
    const tabs = document.querySelector(selectors.tabs);
    if (tabs) {
      const tabSelector = `[data-q-key="${escapeSelector(activeKey)}"]`;
      const activeTab = tabs.querySelector(tabSelector);
      if (activeTab && typeof activeTab.scrollIntoView === 'function') {
        activeTab.scrollIntoView({ block: 'nearest', inline: 'center' });
      }
    }
    const list = document.querySelector(selectors.list);
    if (!list) {
      return;
    }
    const cardSelector = `.qb-questionnaire[data-key="${escapeSelector(activeKey)}"]`;
    const activeCard = list.querySelector(cardSelector);
    if (activeCard && typeof activeCard.scrollIntoView === 'function') {
      activeCard.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
  }

  function renderSectionNav() {
    const nav = document.querySelector(selectors.sectionNav);
    if (!nav) return;

    const emptyLabel = nav.dataset.emptyLabel || 'Select a questionnaire to view its sections';
    const rootLabel = nav.dataset.rootLabel || 'Items without a section';
    const untitledLabel = nav.dataset.untitledLabel || 'Untitled questionnaire';
    nav.innerHTML = '';

    const active = state.questionnaires.find((q) => keyFor(q) === state.activeKey);
    if (!active) {
      const empty = document.createElement('p');
      empty.className = 'qb-section-nav-empty';
      empty.textContent = emptyLabel;
      nav.appendChild(empty);
      return;
    }

    const summary = document.createElement('div');
    summary.className = 'qb-section-nav-summary';
    const activeIndex = state.questionnaires.findIndex((q) => keyFor(q) === state.activeKey);
    const fallbackLabel = activeIndex >= 0 ? `Questionnaire ${activeIndex + 1}` : untitledLabel;
    summary.textContent = active.title && active.title.trim() !== ''
      ? active.title
      : fallbackLabel;
    nav.appendChild(summary);

    const list = document.createElement('ul');
    list.className = 'qb-section-nav-list';

    const rootItems = Array.isArray(active.items) ? active.items.filter((item) => item && item.is_active !== false) : [];
    if (rootItems.length) {
      list.appendChild(buildSectionNavItem(rootLabel, domIdFor('qb-root-items', active), rootItems.length));
    }

    const sections = Array.isArray(active.sections) ? active.sections : [];
    sections.forEach((section, index) => {
      const label = section.title && section.title.trim() !== ''
        ? section.title
        : `Section ${index + 1}`;
      const navLabel = section.is_active === false ? `${label} (Inactive)` : label;
      const itemCount = Array.isArray(section.items)
        ? section.items.filter((item) => item && item.is_active !== false).length
        : 0;
      list.appendChild(buildSectionNavItem(navLabel, domIdFor('qb-section', section), itemCount));
    });

    if (!list.childElementCount) {
      const empty = document.createElement('p');
      empty.className = 'qb-section-nav-empty';
      empty.textContent = emptyLabel;
      nav.appendChild(empty);
      return;
    }

    nav.appendChild(list);
  }

  function buildSectionNavItem(label, targetId, itemCount) {
    const listItem = document.createElement('li');
    listItem.className = 'qb-section-nav-item';

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'qb-section-nav-button';
    button.textContent = label;
    button.dataset.sectionTarget = targetId;
    listItem.appendChild(button);

    if (typeof itemCount === 'number') {
      const count = document.createElement('span');
      count.className = 'qb-section-nav-count';
      count.textContent = String(itemCount);
      listItem.appendChild(count);
    }

    return listItem;
  }

  function handleSectionNavClick(event) {
    const button = event.target.closest('.qb-section-nav-button');
    if (!button) return;
    event.preventDefault();
    const targetId = button.dataset.sectionTarget;
    if (!targetId) return;
    const target = document.getElementById(targetId);
    if (!target) return;
    if (typeof target.scrollIntoView === 'function') {
      target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    highlightSection(target);
  }

  function highlightSection(element) {
    if (!element || typeof element.classList === 'undefined') {
      return;
    }
    element.classList.add('qb-section-highlight');
    window.setTimeout(() => {
      element.classList.remove('qb-section-highlight');
    }, 1500);
  }

  function render() {
    const list = document.querySelector(selectors.list);
    if (!list) return;
    ensureActiveKey();
    const tabs = document.querySelector(selectors.tabs);
    if (tabs) {
      tabs.innerHTML = '';
    }
    list.innerHTML = '';
    const tabEntries = [];
    state.questionnaires.forEach((questionnaire, qIndex) => {
      const card = buildQuestionnaireCard(questionnaire, qIndex);
      list.appendChild(card);
      if (tabs) {
        const key = keyFor(questionnaire);
        const label = questionnaire.title && questionnaire.title.trim() !== ''
          ? questionnaire.title
          : `Questionnaire ${qIndex + 1}`;
        tabEntries.push({
          key,
          label,
          qIndex,
          isActive: key === state.activeKey,
        });
      }
    });
    if (tabs && tabEntries.length) {
      const collator = (typeof Intl !== 'undefined' && typeof Intl.Collator === 'function')
        ? new Intl.Collator(undefined, { sensitivity: 'base', usage: 'sort' })
        : null;
      tabEntries.sort((a, b) => {
        if (collator) {
          return collator.compare(a.label, b.label);
        }
        return a.label.localeCompare(b.label);
      });
      tabEntries.forEach((entry) => {
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'qb-tab';
        tab.setAttribute('role', 'tab');
        tab.setAttribute('data-q-key', entry.key);
        tab.dataset.qIndex = String(entry.qIndex);
        tab.textContent = entry.label;
        const isActive = entry.isActive;
        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        tab.setAttribute('tabindex', isActive ? '0' : '-1');
        tabs.appendChild(tab);
      });
    }
    initSortable();
    updateDirtyState();
    focusActiveQuestionnaire();
    renderSectionNav();
  }

  function buildQuestionnaireCard(questionnaire, qIndex) {
    const card = document.createElement('div');
    card.className = 'qb-questionnaire';
    card.dataset.key = keyFor(questionnaire);
    card.dataset.qIndex = String(qIndex);
    card.setAttribute('role', 'tabpanel');
    const key = keyFor(questionnaire);
    const isActive = key === state.activeKey;
    card.hidden = !isActive;
    card.setAttribute('aria-hidden', isActive ? 'false' : 'true');

    const header = document.createElement('div');
    header.className = 'qb-questionnaire-header';

    const handle = document.createElement('span');
    handle.className = 'qb-handle qb-card-handle';
    handle.setAttribute('title', 'Drag to reorder questionnaire');
    handle.setAttribute('aria-hidden', 'true');
    header.appendChild(handle);

    const titleWrap = document.createElement('div');
    titleWrap.className = 'qb-questionnaire-fields';
    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'qb-input qb-title';
    titleInput.value = questionnaire.title;
    titleInput.placeholder = 'Questionnaire title';
    titleInput.dataset.role = 'q-title';
    titleInput.dataset.qIndex = String(qIndex);
    titleWrap.appendChild(titleInput);

    const desc = document.createElement('textarea');
    desc.className = 'qb-textarea qb-description';
    desc.value = questionnaire.description || '';
    desc.placeholder = 'Description';
    desc.dataset.role = 'q-description';
    desc.dataset.qIndex = String(qIndex);
    titleWrap.appendChild(desc);

    header.appendChild(titleWrap);

    const statusValue = String(questionnaire.status || 'draft').toLowerCase();
    if (statusValue === 'inactive') {
      card.classList.add('qb-inactive');
    }
    const statusWrap = document.createElement('div');
    statusWrap.className = 'qb-status-control';
    const statusBadge = document.createElement('span');
    statusBadge.className = `qb-status-badge qb-status-${statusValue}`;
    statusBadge.textContent = formatStatusLabel(statusValue);
    statusWrap.appendChild(statusBadge);
    const statusSelect = document.createElement('select');
    statusSelect.className = 'qb-select qb-status-select';
    statusSelect.dataset.role = 'q-status';
    statusSelect.dataset.qIndex = String(qIndex);
    STATUS_OPTIONS.forEach((optionValue) => {
      const opt = document.createElement('option');
      opt.value = optionValue;
      opt.textContent = formatStatusLabel(optionValue);
      if (optionValue === statusValue) {
        opt.selected = true;
      }
      statusSelect.appendChild(opt);
    });
    statusWrap.appendChild(statusSelect);
    const responseCount = Number(questionnaire.responseCount) || 0;
    if (responseCount > 0) {
      const responseBadge = document.createElement('span');
      responseBadge.className = 'qb-response-pill';
      responseBadge.textContent = `${responseCount} response${responseCount === 1 ? '' : 's'}`;
      statusWrap.appendChild(responseBadge);
    } else if (questionnaire.hasResponses) {
      const responseBadge = document.createElement('span');
      responseBadge.className = 'qb-response-pill';
      responseBadge.textContent = 'Linked responses';
      statusWrap.appendChild(responseBadge);
    }
    header.appendChild(statusWrap);

    const actions = document.createElement('div');
    actions.className = 'qb-questionnaire-actions';

    const addSectionBtn = document.createElement('button');
    addSectionBtn.className = 'md-button qb-action';
    addSectionBtn.textContent = 'Add Section';
    addSectionBtn.dataset.action = 'add-section';
    addSectionBtn.dataset.qIndex = String(qIndex);
    actions.appendChild(addSectionBtn);

    const addItemBtn = document.createElement('button');
    addItemBtn.className = 'md-button qb-action';
    addItemBtn.textContent = 'Add Item';
    addItemBtn.dataset.action = 'add-item';
    addItemBtn.dataset.qIndex = String(qIndex);
    addItemBtn.dataset.sectionIndex = 'root';
    actions.appendChild(addItemBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'md-button qb-danger';
    deleteBtn.textContent = (questionnaire.hasResponses || responseCount > 0) ? 'Mark Inactive' : 'Delete';
    deleteBtn.dataset.action = 'delete-questionnaire';
    deleteBtn.dataset.qIndex = String(qIndex);
    actions.appendChild(deleteBtn);

    header.appendChild(actions);
    card.appendChild(header);

    const scoringSummary = buildScoringSummary(computeQuestionnaireScoring(questionnaire, qIndex));
    card.appendChild(scoringSummary);

    const sectionsContainer = document.createElement('div');
    sectionsContainer.className = 'qb-section-list';
    sectionsContainer.dataset.sortable = 'sections';
    sectionsContainer.dataset.qIndex = String(qIndex);
    questionnaire.sections.forEach((section, sectionIndex) => {
      const sectionEl = buildSection(section, qIndex, sectionIndex);
      sectionsContainer.appendChild(sectionEl);
    });
    card.appendChild(sectionsContainer);

    const rootItems = document.createElement('div');
    rootItems.className = 'qb-item-list qb-root-items';
    rootItems.dataset.sortable = 'items';
    rootItems.dataset.qIndex = String(qIndex);
    rootItems.dataset.sectionIndex = 'root';
    rootItems.id = domIdFor('qb-root-items', questionnaire);
    questionnaire.items.forEach((item, itemIndex) => {
      const itemEl = buildItem(item, qIndex, 'root', itemIndex);
      rootItems.appendChild(itemEl);
    });
    if (questionnaire.items.length) {
      const heading = document.createElement('div');
      heading.className = 'qb-inline-heading';
      heading.textContent = 'Items without a section';
      card.appendChild(heading);
    }
    card.appendChild(rootItems);

    return card;
  }

  function buildSection(section, qIndex, sectionIndex) {
    const sectionEl = document.createElement('div');
    sectionEl.className = 'qb-section';
    sectionEl.dataset.key = keyFor(section);
    sectionEl.dataset.qIndex = String(qIndex);
    sectionEl.dataset.sectionIndex = String(sectionIndex);
    sectionEl.id = domIdFor('qb-section', section);
    if (section.is_active === false) {
      sectionEl.classList.add('qb-inactive');
    }
    if (section.hasResponses) {
      sectionEl.dataset.hasResponses = 'true';
    }

    const header = document.createElement('div');
    header.className = 'qb-section-header';

    const handle = document.createElement('span');
    handle.className = 'qb-handle';
    handle.setAttribute('title', 'Drag to reorder section');
    handle.setAttribute('aria-hidden', 'true');
    header.appendChild(handle);

    const fields = document.createElement('div');
    fields.className = 'qb-section-fields';

    const title = document.createElement('input');
    title.type = 'text';
    title.className = 'qb-input qb-section-title';
    title.value = section.title;
    title.placeholder = 'Section title';
    title.dataset.role = 'section-title';
    title.dataset.qIndex = String(qIndex);
    title.dataset.sectionIndex = String(sectionIndex);
    fields.appendChild(title);

    const desc = document.createElement('textarea');
    desc.className = 'qb-textarea qb-section-description';
    desc.value = section.description || '';
    desc.placeholder = 'Description';
    desc.dataset.role = 'section-description';
    desc.dataset.qIndex = String(qIndex);
    desc.dataset.sectionIndex = String(sectionIndex);
    fields.appendChild(desc);

    header.appendChild(fields);

    const actions = document.createElement('div');
    actions.className = 'qb-section-actions';

    const activeToggle = document.createElement('label');
    activeToggle.className = 'qb-checkbox qb-status-toggle';
    const activeInput = document.createElement('input');
    activeInput.type = 'checkbox';
    activeInput.checked = section.is_active !== false;
    activeInput.dataset.role = 'section-active';
    activeInput.dataset.qIndex = String(qIndex);
    activeInput.dataset.sectionIndex = String(sectionIndex);
    const activeText = document.createElement('span');
    activeText.textContent = 'Active';
    activeToggle.appendChild(activeInput);
    activeToggle.appendChild(activeText);
    actions.appendChild(activeToggle);
    if (section.hasResponses) {
      const responseBadge = document.createElement('span');
      responseBadge.className = 'qb-response-pill';
      responseBadge.textContent = 'Linked responses';
      actions.appendChild(responseBadge);
    }

    const addItemBtn = document.createElement('button');
    addItemBtn.className = 'md-button qb-action';
    addItemBtn.textContent = 'Add Item';
    addItemBtn.dataset.action = 'add-item';
    addItemBtn.dataset.qIndex = String(qIndex);
    addItemBtn.dataset.sectionIndex = String(sectionIndex);
    actions.appendChild(addItemBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'md-button qb-danger';
    deleteBtn.textContent = section.hasResponses ? 'Mark Inactive' : 'Delete';
    deleteBtn.dataset.action = 'delete-section';
    deleteBtn.dataset.qIndex = String(qIndex);
    deleteBtn.dataset.sectionIndex = String(sectionIndex);
    actions.appendChild(deleteBtn);

    header.appendChild(actions);
    sectionEl.appendChild(header);

    const items = document.createElement('div');
    items.className = 'qb-item-list';
    items.dataset.sortable = 'items';
    items.dataset.qIndex = String(qIndex);
    items.dataset.sectionIndex = String(sectionIndex);
    section.items.forEach((item, itemIndex) => {
      const itemEl = buildItem(item, qIndex, sectionIndex, itemIndex);
      items.appendChild(itemEl);
    });
    sectionEl.appendChild(items);

    return sectionEl;
  }

  function buildItem(item, qIndex, sectionIndex, itemIndex) {
    const itemEl = document.createElement('div');
    itemEl.className = 'qb-item';
    itemEl.dataset.key = keyFor(item);
    itemEl.dataset.qIndex = String(qIndex);
    itemEl.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    itemEl.dataset.itemIndex = String(itemIndex);
    if (item.is_active === false) {
      itemEl.classList.add('qb-inactive');
    }
    if (item.hasResponses) {
      itemEl.dataset.hasResponses = 'true';
    }

    const handle = document.createElement('span');
    handle.className = 'qb-handle';
    handle.setAttribute('title', 'Drag to reorder item');
    handle.setAttribute('aria-hidden', 'true');
    itemEl.appendChild(handle);

    const linkId = document.createElement('input');
    linkId.type = 'text';
    linkId.className = 'qb-input qb-link-id';
    linkId.placeholder = 'linkId';
    linkId.value = item.linkId;
    linkId.dataset.role = 'item-linkId';
    linkId.dataset.qIndex = String(qIndex);
    linkId.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    linkId.dataset.itemIndex = String(itemIndex);
    itemEl.appendChild(linkId);

    const text = document.createElement('input');
    text.type = 'text';
    text.className = 'qb-input qb-item-text';
    text.placeholder = 'Prompt text';
    text.value = item.text;
    text.dataset.role = 'item-text';
    text.dataset.qIndex = String(qIndex);
    text.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    text.dataset.itemIndex = String(itemIndex);
    itemEl.appendChild(text);

    const type = document.createElement('select');
    type.className = 'qb-select qb-item-type';
    QUESTION_TYPES.forEach((optionValue) => {
      const opt = document.createElement('option');
      opt.value = optionValue;
      opt.textContent = optionValue;
      if (optionValue === item.type) {
        opt.selected = true;
      }
      type.appendChild(opt);
    });
    type.dataset.role = 'item-type';
    type.dataset.qIndex = String(qIndex);
    type.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    type.dataset.itemIndex = String(itemIndex);
    itemEl.appendChild(type);

    const weightWrap = document.createElement('label');
    weightWrap.className = 'qb-field qb-weight-field';
    const weightLabel = document.createElement('span');
    weightLabel.className = 'qb-field-label';
    weightLabel.textContent = STRINGS.scoreWeightLabel;
    const weight = document.createElement('input');
    weight.type = 'number';
    weight.min = '0';
    weight.max = '100';
    weight.step = '1';
    weight.className = 'qb-input qb-weight';
    weight.placeholder = '0';
    weight.value = String(item.weight_percent ?? 0);
    weight.dataset.role = 'item-weight';
    weight.dataset.qIndex = String(qIndex);
    weight.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    weight.dataset.itemIndex = String(itemIndex);
    const weightHint = document.createElement('span');
    weightHint.className = 'qb-field-hint';
    weightHint.textContent = STRINGS.scoreWeightHint;
    weightWrap.appendChild(weightLabel);
    weightWrap.appendChild(weight);
    weightWrap.appendChild(weightHint);
    itemEl.appendChild(weightWrap);

    const requiredWrap = document.createElement('label');
    requiredWrap.className = 'qb-checkbox qb-required-toggle';
    const requiredInput = document.createElement('input');
    requiredInput.type = 'checkbox';
    requiredInput.checked = Boolean(item.is_required);
    requiredInput.dataset.role = 'item-required';
    requiredInput.dataset.qIndex = String(qIndex);
    requiredInput.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    requiredInput.dataset.itemIndex = String(itemIndex);
    const requiredText = document.createElement('span');
    requiredText.textContent = 'Required response';
    requiredWrap.appendChild(requiredInput);
    requiredWrap.appendChild(requiredText);
    itemEl.appendChild(requiredWrap);

    const activeWrap = document.createElement('label');
    activeWrap.className = 'qb-checkbox qb-status-toggle';
    const activeInput = document.createElement('input');
    activeInput.type = 'checkbox';
    activeInput.checked = item.is_active !== false;
    activeInput.dataset.role = 'item-active';
    activeInput.dataset.qIndex = String(qIndex);
    activeInput.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    activeInput.dataset.itemIndex = String(itemIndex);
    const activeText = document.createElement('span');
    activeText.textContent = 'Active';
    activeWrap.appendChild(activeInput);
    activeWrap.appendChild(activeText);
    itemEl.appendChild(activeWrap);
    if (item.hasResponses) {
      const responseBadge = document.createElement('span');
      responseBadge.className = 'qb-response-pill';
      responseBadge.textContent = 'Linked responses';
      itemEl.appendChild(responseBadge);
    }

    if (isOptionType(item.type)) {
      const choiceWrap = document.createElement('div');
      choiceWrap.className = 'qb-choice-settings';

      const isLikert = item.type === 'likert';

      if (!isLikert) {
        const allowWrap = document.createElement('label');
        allowWrap.className = 'qb-checkbox';
        const allowInput = document.createElement('input');
        allowInput.type = 'checkbox';
        allowInput.checked = Boolean(item.allow_multiple);
        allowInput.dataset.role = 'item-allow-multiple';
        allowInput.dataset.qIndex = String(qIndex);
        allowInput.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
        allowInput.dataset.itemIndex = String(itemIndex);
        const allowText = document.createElement('span');
        allowText.textContent = 'Allow multiple selections';
        allowWrap.appendChild(allowInput);
        allowWrap.appendChild(allowText);
        choiceWrap.appendChild(allowWrap);
      } else {
        const note = document.createElement('p');
        note.className = 'qb-likert-note';
        note.textContent = 'Likert scale responses are fixed to a 1–5 rating.';
        choiceWrap.appendChild(note);
      }

      const optionsHeading = document.createElement('div');
      optionsHeading.className = 'qb-inline-heading';
      optionsHeading.textContent = isLikert ? 'Scale points' : 'Options';
      choiceWrap.appendChild(optionsHeading);

      const optionsList = document.createElement('div');
      optionsList.className = 'qb-option-list';
      optionsList.dataset.sortable = 'options';
      optionsList.dataset.qIndex = String(qIndex);
      optionsList.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
      optionsList.dataset.itemIndex = String(itemIndex);
      item.options = Array.isArray(item.options) ? item.options : [];
      if (isLikert) {
        item.options = ensureLikertOptions(item.options);
        optionsList.dataset.locked = 'true';
      }
      item.options.forEach((option, optionIndex) => {
        const optionEl = buildOption(option, qIndex, sectionIndex, itemIndex, optionIndex, item.type);
        optionsList.appendChild(optionEl);
      });
      choiceWrap.appendChild(optionsList);

      if (!isLikert) {
        const addOptionBtn = document.createElement('button');
        addOptionBtn.className = 'md-button qb-action';
        addOptionBtn.textContent = 'Add Option';
        addOptionBtn.dataset.action = 'add-option';
        addOptionBtn.dataset.qIndex = String(qIndex);
        addOptionBtn.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
        addOptionBtn.dataset.itemIndex = String(itemIndex);
        choiceWrap.appendChild(addOptionBtn);
      }

      itemEl.appendChild(choiceWrap);
    }

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'md-button qb-danger';
    deleteBtn.textContent = item.hasResponses ? 'Mark Inactive' : 'Delete';
    deleteBtn.dataset.action = 'delete-item';
    deleteBtn.dataset.qIndex = String(qIndex);
    deleteBtn.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    deleteBtn.dataset.itemIndex = String(itemIndex);
    itemEl.appendChild(deleteBtn);

    return itemEl;
  }

  function buildOption(option, qIndex, sectionIndex, itemIndex, optionIndex, itemType = 'choice') {
    const optionEl = document.createElement('div');
    optionEl.className = 'qb-option';
    optionEl.dataset.key = keyFor(option);
    optionEl.dataset.qIndex = String(qIndex);
    optionEl.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    optionEl.dataset.itemIndex = String(itemIndex);
    optionEl.dataset.optionIndex = String(optionIndex);

    const handle = document.createElement('span');
    handle.className = 'qb-handle';
    handle.setAttribute('title', 'Drag to reorder option');
    handle.setAttribute('aria-hidden', 'true');
    optionEl.appendChild(handle);

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'qb-input qb-option-input';
    input.placeholder = 'Option label';
    input.value = option.value ?? '';
    input.dataset.role = 'option-value';
    input.dataset.qIndex = String(qIndex);
    input.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    input.dataset.itemIndex = String(itemIndex);
    input.dataset.optionIndex = String(optionIndex);
    if (itemType === 'likert') {
      input.readOnly = true;
      input.classList.add('qb-option-readonly');
    }
    optionEl.appendChild(input);

    if (itemType !== 'likert') {
      const deleteBtn = document.createElement('button');
      deleteBtn.className = 'md-button qb-danger';
      deleteBtn.textContent = 'Delete';
      deleteBtn.dataset.action = 'delete-option';
      deleteBtn.dataset.qIndex = String(qIndex);
      deleteBtn.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
      deleteBtn.dataset.itemIndex = String(itemIndex);
      deleteBtn.dataset.optionIndex = String(optionIndex);
      optionEl.appendChild(deleteBtn);
    }

    return optionEl;
  }

  function initSortable() {
    const list = document.querySelector(selectors.list);
    if (list) {
      makeSortable(list, {
        handle: '.qb-card-handle',
        animation: 120,
        onEnd() {
          const keys = Array.from(list.children).map((el) => el.dataset.key);
          state.questionnaires.sort((a, b) => keys.indexOf(keyFor(a)) - keys.indexOf(keyFor(b)));
          markDirty();
          render();
        },
      });
    }

    document.querySelectorAll('[data-sortable="sections"]').forEach((container) => {
      makeSortable(container, {
        handle: '.qb-section-header > .qb-handle',
        animation: 120,
        onEnd() {
          const qIndex = parseInt(container.dataset.qIndex ?? '-1', 10);
          if (Number.isNaN(qIndex) || !state.questionnaires[qIndex]) return;
          const sections = state.questionnaires[qIndex].sections;
          const orderedKeys = Array.from(container.children).map((el) => el.dataset.key);
          state.questionnaires[qIndex].sections = orderedKeys
            .map((key) => sections.find((section) => keyFor(section) === key))
            .filter(Boolean);
          markDirty();
          render();
        },
      });
    });

    document.querySelectorAll('[data-sortable="items"]').forEach((container) => {
      makeSortable(container, {
        handle: '.qb-item > .qb-handle',
        animation: 120,
        onEnd() {
          const qIndex = parseInt(container.dataset.qIndex ?? '-1', 10);
          const sectionIndex = parseSectionIndex(container.dataset.sectionIndex);
          const listRef = getItemList(qIndex, sectionIndex);
          if (!listRef) return;
          const orderedKeys = Array.from(container.children).map((el) => el.dataset.key);
          const sorted = orderedKeys
            .map((key) => listRef.find((item) => keyFor(item) === key))
            .filter(Boolean);
          if (sorted.length === listRef.length) {
            if (sectionIndex === 'root' || sectionIndex === null) {
              state.questionnaires[qIndex].items = sorted;
            } else if (state.questionnaires[qIndex].sections[sectionIndex]) {
              state.questionnaires[qIndex].sections[sectionIndex].items = sorted;
            }
            markDirty();
            render();
          }
        },
      });
    });

    document.querySelectorAll('[data-sortable="options"]').forEach((container) => {
      if (container.dataset.locked === 'true') {
        if (window.Sortable) {
          const existing = window.Sortable.get(container);
          if (existing) existing.destroy();
        }
        return;
      }
      makeSortable(container, {
        handle: '.qb-option > .qb-handle',
        animation: 120,
        onEnd() {
          const qIndex = parseInt(container.dataset.qIndex ?? '-1', 10);
          const sectionIndex = parseSectionIndex(container.dataset.sectionIndex);
          const itemIndex = parseInt(container.dataset.itemIndex ?? '-1', 10);
          const optionList = getOptionList(qIndex, sectionIndex, itemIndex);
          if (!optionList) return;
          const orderedKeys = Array.from(container.children).map((el) => el.dataset.key);
          const sorted = orderedKeys
            .map((key) => optionList.find((option) => keyFor(option) === key))
            .filter(Boolean);
          if (sorted.length === optionList.length) {
            optionList.length = 0;
            sorted.forEach((option) => optionList.push(option));
            markDirty();
            render();
          }
        },
      });
    });
  }

  function makeSortable(element, options) {
    if (!window.Sortable || !element) return;
    const existing = window.Sortable.get(element);
    if (existing) existing.destroy();
    window.Sortable.create(element, options);
  }

  async function saveStructure(publish = false) {
    if (state.loading || state.saving) return;
    state.saving = true;
    updateDirtyState();
    setMessage(publish ? 'Publishing...' : 'Saving...', 'info');
    try {
      const payloadQuestionnaires = state.questionnaires.map((questionnaire) => serializeQuestionnaire(questionnaire, publish));
      const response = await fetch(withBase(`/admin/questionnaire_manage.php?action=${publish ? 'publish' : 'save'}`), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': state.csrfToken,
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ questionnaires: payloadQuestionnaires }),
      });
      if (!response.ok) {
        throw new Error(`Failed to ${publish ? 'publish' : 'save'} (${response.status})`);
      }
      const data = await response.json();
      if (data.csrf) {
        updateCsrf(data.csrf);
      }
      if (data.idMap) {
        applyIdMap(data.idMap);
      }
      state.dirty = false;
      setMessage(data.message || (publish ? 'Published successfully' : 'Saved successfully'), 'success');
      await fetchData({ silent: true });
    } catch (error) {
      console.error(error);
      setMessage(error.message || 'Failed to save questionnaires', 'error');
    } finally {
      state.saving = false;
      updateDirtyState();
    }
  }

  function applyIdMap(idMap) {
    if (!idMap) return;
    const qMap = idMap.questionnaires || {};
    const sMap = idMap.sections || {};
    const iMap = idMap.items || {};
    const oMap = idMap.options || {};
    state.questionnaires.forEach((q) => {
      if (!q.id && qMap[q.clientId]) {
        q.id = qMap[q.clientId];
      }
      q.sections.forEach((section) => {
        if (!section.id && sMap[section.clientId]) {
          section.id = sMap[section.clientId];
        }
        section.items.forEach((item) => {
          if (!item.id && iMap[item.clientId]) {
            item.id = iMap[item.clientId];
          }
          item.options.forEach((option) => {
            if (!option.id && oMap[option.clientId]) {
              option.id = oMap[option.clientId];
            }
          });
        });
      });
      q.items.forEach((item) => {
        if (!item.id && iMap[item.clientId]) {
          item.id = iMap[item.clientId];
        }
        item.options.forEach((option) => {
          if (!option.id && oMap[option.clientId]) {
            option.id = oMap[option.clientId];
          }
        });
      });
    });
  }

  return { init };
})();

window.addEventListener('DOMContentLoaded', () => {
  Builder.init();
});
