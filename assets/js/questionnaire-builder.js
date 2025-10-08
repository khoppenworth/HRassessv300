const Builder = (() => {
  const state = {
    questionnaires: [],
    dirty: false,
    loading: false,
    saving: false,
    csrfToken: '',
  };

  const selectors = {
    addButton: '#qb-add-questionnaire',
    saveButton: '#qb-save',
    publishButton: '#qb-publish',
    message: '#qb-message',
    list: '#qb-list',
    metaCsrf: 'meta[name="csrf-token"]',
  };

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

    fetchData();
  }

  function uuid(prefix = 'tmp') {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return `${prefix}-${window.crypto.randomUUID()}`;
    }
    return `${prefix}-${Math.random().toString(36).slice(2, 10)}`;
  }

  function handleInputChange(event) {
    const target = event.target;
    const role = target.dataset.role;
    if (!role) return;
    const qIndex = parseInt(target.dataset.qIndex ?? '-1', 10);
    if (Number.isNaN(qIndex) || !state.questionnaires[qIndex]) return;

    if (role === 'q-title') {
      state.questionnaires[qIndex].title = target.value;
    } else if (role === 'q-description') {
      state.questionnaires[qIndex].description = target.value;
    } else if (role === 'section-title' || role === 'section-description') {
      const sectionIndex = parseSectionIndex(target.dataset.sectionIndex);
      const section = getSection(qIndex, sectionIndex);
      if (!section) return;
      if (role === 'section-title') {
        section.title = target.value;
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
        item.type = target.value;
      } else if (role === 'item-weight') {
        item.weight_percent = parseInt(target.value || '0', 10) || 0;
      }
    }
    markDirty();
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

  function addQuestionnaire() {
    const questionnaire = {
      id: null,
      clientId: uuid('q'),
      title: 'Untitled Questionnaire',
      description: '',
      sections: [],
      items: [],
    };
    state.questionnaires.unshift(questionnaire);
    markDirty();
    render();
  }

  function removeQuestionnaire(qIndex) {
    if (Number.isNaN(qIndex) || !state.questionnaires[qIndex]) return;
    if (!window.confirm('Delete this questionnaire and all of its content?')) return;
    state.questionnaires.splice(qIndex, 1);
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
    });
    markDirty();
    render();
  }

  function removeSection(qIndex, sectionIndex) {
    const questionnaire = state.questionnaires[qIndex];
    if (!questionnaire || sectionIndex === null || sectionIndex === 'root') return;
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
      type: 'text',
      weight_percent: 0,
    });
    markDirty();
    render();
  }

  function removeItem(qIndex, sectionIndex, itemIndex) {
    const list = getItemList(qIndex, sectionIndex);
    if (!list || Number.isNaN(itemIndex) || !list[itemIndex]) return;
    list.splice(itemIndex, 1);
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
      sections: [],
      items: [],
    };
    const sections = Array.isArray(q.sections) ? q.sections : [];
    questionnaire.sections = sections.map((section) => ({
      id: section.id ?? null,
      clientId: section.clientId || `s-${section.id ?? uuid('s')}`,
      title: section.title ?? '',
      description: section.description ?? '',
      items: normalizeItems(section.items),
    }));
    questionnaire.items = normalizeItems(q.items);
    return questionnaire;
  }

  function normalizeItems(items) {
    if (!Array.isArray(items)) return [];
    return items.map((item) => ({
      id: item.id ?? null,
      clientId: item.clientId || `i-${item.id ?? uuid('i')}`,
      linkId: item.linkId ?? '',
      text: item.text ?? '',
      type: item.type ?? 'text',
      weight_percent: Number.isFinite(item.weight_percent) ? item.weight_percent : parseInt(item.weight_percent || '0', 10) || 0,
    }));
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

  function render() {
    const list = document.querySelector(selectors.list);
    if (!list) return;
    list.innerHTML = '';
    state.questionnaires.forEach((questionnaire, qIndex) => {
      const card = buildQuestionnaireCard(questionnaire, qIndex);
      list.appendChild(card);
    });
    initSortable();
    updateDirtyState();
  }

  function buildQuestionnaireCard(questionnaire, qIndex) {
    const card = document.createElement('div');
    card.className = 'qb-questionnaire';
    card.dataset.key = keyFor(questionnaire);
    card.dataset.qIndex = String(qIndex);

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
    deleteBtn.textContent = 'Delete';
    deleteBtn.dataset.action = 'delete-questionnaire';
    deleteBtn.dataset.qIndex = String(qIndex);
    actions.appendChild(deleteBtn);

    header.appendChild(actions);
    card.appendChild(header);

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

    const addItemBtn = document.createElement('button');
    addItemBtn.className = 'md-button qb-action';
    addItemBtn.textContent = 'Add Item';
    addItemBtn.dataset.action = 'add-item';
    addItemBtn.dataset.qIndex = String(qIndex);
    addItemBtn.dataset.sectionIndex = String(sectionIndex);
    actions.appendChild(addItemBtn);

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'md-button qb-danger';
    deleteBtn.textContent = 'Delete';
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
    ['text', 'textarea', 'boolean'].forEach((optionValue) => {
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

    const weight = document.createElement('input');
    weight.type = 'number';
    weight.min = '0';
    weight.max = '100';
    weight.className = 'qb-input qb-weight';
    weight.placeholder = 'Weight %';
    weight.value = String(item.weight_percent ?? 0);
    weight.dataset.role = 'item-weight';
    weight.dataset.qIndex = String(qIndex);
    weight.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    weight.dataset.itemIndex = String(itemIndex);
    itemEl.appendChild(weight);

    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'md-button qb-danger';
    deleteBtn.textContent = 'Delete';
    deleteBtn.dataset.action = 'delete-item';
    deleteBtn.dataset.qIndex = String(qIndex);
    deleteBtn.dataset.sectionIndex = sectionIndex === 'root' ? 'root' : String(sectionIndex);
    deleteBtn.dataset.itemIndex = String(itemIndex);
    itemEl.appendChild(deleteBtn);

    return itemEl;
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
      const response = await fetch(withBase(`/admin/questionnaire_manage.php?action=${publish ? 'publish' : 'save'}`), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': state.csrfToken,
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ questionnaires: state.questionnaires }),
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
        });
      });
      q.items.forEach((item) => {
        if (!item.id && iMap[item.clientId]) {
          item.id = iMap[item.clientId];
        }
      });
    });
  }

  return { init };
})();

window.addEventListener('DOMContentLoaded', () => {
  Builder.init();
});
