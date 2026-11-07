/**
 * «Маппинг затрат → ОПиУ» (templates/marketplace/cost_pl_mapping/index.html.twig): модал
 * удаления категории, модал правки маппинга строки (POST JSON по data-update-url строки,
 * CSRF в заголовке) и модал базового маппинга затрат (preview/apply по data-* корня
 * [data-default-mapping-modal]). Данные API — только textContent.
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';
import { el } from '../dom.js';

const STATUS_LABELS = {
  will_create: 'Будет создано',
  will_fill_empty: 'Будет дополнено',
  skipped_existing: 'Уже настроено',
  skipped_disabled: 'Отключено вручную',
  missing_cost_category: 'Нет категории МП',
  missing_pl_category: 'Нет статьи ОПиУ',
  invalid_target_category: 'Невалидная статья ОПиУ',
};

// Эндпоинты базового маппинга отвечают 200 с {ok: false, message} на доменную ошибку.
async function postOk(url, body) {
  const data = await apiFetch(url, { method: 'POST', body });
  if (!data || data.ok === false) throw new Error(data?.message || 'Ошибка запроса. Попробуйте позже.');
  return data;
}

function initDeleteModal() {
  const modal = document.querySelector('[data-delete-cost-category-modal]');
  modal?.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!(trigger instanceof HTMLElement) || !trigger.dataset.url) {
      event.preventDefault();
      return;
    }
    modal.querySelector('[data-delete-cost-category-name]').textContent = `"${trigger.dataset.name ?? ''}"`;
    modal.querySelector('[data-delete-cost-category-form]').action = trigger.dataset.url;
  });
}

function initEditModal() {
  const modal = document.querySelector('[data-edit-mapping-modal]');
  if (!modal) return;
  const form = modal.querySelector('[data-edit-mapping-form]');
  const nameEl = modal.querySelector('[data-edit-mapping-name]');
  const errorEl = modal.querySelector('[data-edit-mapping-error]');
  const danglingEl = modal.querySelector('[data-edit-mapping-dangling]');
  const plSelect = modal.querySelector('[data-edit-mapping-pl]');
  const includeInput = modal.querySelector('[data-edit-mapping-include]');
  const sortInput = modal.querySelector('[data-edit-mapping-sort]');
  const submitBtn = modal.querySelector('[data-edit-mapping-submit]');
  let currentRow = null;
  let inFlight = false;

  const plName = (id) => (id ? plSelect.querySelector(`option[value="${CSS.escape(id)}"]`)?.dataset.name ?? '' : '');
  const badge = (text, css) => el('span', `badge ${css}`, [text]);

  function renderRow(row, data) {
    row.dataset.plCategoryId = data.plCategoryId || '';
    row.dataset.includeInPl = data.includeInPl ? '1' : '0';
    row.dataset.sortOrder = String(data.sortOrder);
    const plCell = row.querySelector('[data-cell-pl]');
    if (data.plCategoryId) {
      plCell.textContent = plName(data.plCategoryId) || 'Статья ОПиУ не найдена';
    } else {
      plCell.replaceChildren(el('span', 'text-muted', ['— не задана —']));
    }
    row.querySelector('[data-cell-include]').replaceChildren(data.includeInPl ? badge('Да', 'bg-green-lt') : badge('Нет', 'bg-secondary-lt'));
    row.querySelector('[data-cell-sort]').textContent = String(data.sortOrder);
  }

  modal.addEventListener('show.bs.modal', (event) => {
    const row = event.relatedTarget instanceof Element ? event.relatedTarget.closest('[data-mapping-row]') : null;
    if (!row) {
      event.preventDefault();
      return;
    }
    currentRow = row;
    errorEl.classList.add('d-none');
    submitBtn.disabled = false;
    nameEl.textContent = row.dataset.name ?? '';
    const plCategoryId = row.dataset.plCategoryId ?? '';
    plSelect.value = plCategoryId;
    // Статья строки удалена из дерева: option нет, select её не принял.
    const dangling = plSelect.value !== plCategoryId;
    if (dangling) plSelect.value = '';
    danglingEl.classList.toggle('d-none', !dangling);
    includeInput.checked = row.dataset.includeInPl === '1';
    sortInput.value = row.dataset.sortOrder ?? '';
  });

  // Пока запрос в полёте, модалку не закрыть и не открыть на другой строке:
  // иначе поздний ответ попадёт в чужую строку.
  modal.addEventListener('hide.bs.modal', (event) => {
    if (inFlight) event.preventDefault();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!currentRow || inFlight) return;
    const sortOrder = Number(sortInput.value);
    if (!sortInput.checkValidity() || !Number.isInteger(sortOrder)) {
      sortInput.reportValidity();
      return;
    }
    const row = currentRow;
    inFlight = true;
    submitBtn.disabled = true;
    errorEl.classList.add('d-none');
    try {
      const data = await apiFetch(row.dataset.updateUrl, {
        method: 'POST',
        csrfToken: form.dataset.csrfToken,
        body: { plCategoryId: plSelect.value || null, includeInPl: includeInput.checked, sortOrder },
      });
      renderRow(row, data);
      inFlight = false;
      bootstrapModal()?.getOrCreateInstance(modal).hide();
    } catch (error) {
      errorEl.textContent = error.message || 'Не удалось сохранить. Обновите страницу и повторите попытку.';
      errorEl.classList.remove('d-none');
      submitBtn.disabled = false;
    } finally {
      inFlight = false;
    }
  });
}

function initDefaultMappingModal() {
  const root = document.querySelector('[data-default-mapping-modal]');
  if (!(root instanceof HTMLElement)) return;
  const modal = root.closest('.modal');
  const marketplaceSelect = document.querySelector('[data-marketplace-select]');
  const q = (name) => root.querySelector(`[data-default-mapping-${name}]`);
  const loadingEl = q('loading');
  const previewEl = q('preview');
  const emptyEl = q('empty');
  const errorEl = q('error');
  const successEl = q('success');
  const blockingEl = q('blocking');
  const warningEl = q('warning');
  const hintEl = q('apply-hint');
  const applyBtn = q('apply');
  const state = { preview: null, loading: false, applying: false, requested: false };

  const currentMarketplace = () => marketplaceSelect?.value ?? '';
  const hideAlerts = () => [errorEl, successEl, blockingEl, warningEl].forEach((node) => node.classList.add('d-none'));
  const showError = (message) => {
    errorEl.textContent = message;
    errorEl.classList.remove('d-none');
  };

  function updateApplyState() {
    const summary = state.preview?.summary || {};
    const actionable = (summary.will_create || 0) + (summary.will_fill_empty || 0);
    const hasBlocking = Boolean(state.preview?.hasBlockingIssues);
    applyBtn.disabled = !state.preview || state.loading || state.applying || hasBlocking || actionable === 0;
    if (!state.preview) {
      hintEl.textContent = 'Сначала загрузите preview.';
    } else if (hasBlocking) {
      hintEl.textContent = 'Применение недоступно из-за блокирующих проблем.';
    } else if (actionable === 0) {
      hintEl.textContent = 'Нет новых маппингов для применения.';
    } else {
      hintEl.textContent = '';
    }
  }

  function itemCard(item, key) {
    const conf = item.confidence || 'n/a';
    const confClass = conf === 'high' ? 'bg-green-lt' : (conf === 'medium' ? 'bg-yellow-lt' : 'bg-red-lt');
    return el('div', 'border rounded p-2 mb-2', [
      el('div', 'd-flex justify-content-between', [
        el('span', 'badge bg-blue-lt', [STATUS_LABELS[item.status] || item.status || key]),
        el('span', `badge ${confClass}`, [conf]),
      ]),
      el('div', 'small mt-2', [el('strong', '', ['Затрата:']), ` ${item.costCode ?? ''} — ${item.costCategoryName ?? ''}`]),
      el('div', 'small', [el('strong', '', ['ОПиУ:']), ` ${item.plCode ?? ''} — ${item.plCategoryName ?? ''}`]),
      el('div', 'small text-muted', [`${item.note || ''} ${item.message || ''}`]),
    ]);
  }

  function renderPreview(payload) {
    previewEl.replaceChildren();
    emptyEl.classList.add('d-none');
    const summary = payload.summary || {};
    const allItems = Array.isArray(payload.items) ? payload.items : [];
    if (allItems.length === 0) {
      emptyEl.classList.remove('d-none');
      return;
    }
    if (payload.hasBlockingIssues) blockingEl.classList.remove('d-none');
    if ((summary.missing_cost_category || 0) > 0) warningEl.classList.remove('d-none');

    const keys = Object.keys(STATUS_LABELS);
    const cards = el('div', 'row g-2 mb-3', keys.map((key) => el('div', 'col-md-3 col-sm-6', [el('div', 'card card-sm', [el('div', 'card-body', [
      el('div', 'text-muted small', [STATUS_LABELS[key]]),
      el('div', 'h2 m-0', [String(summary[key] || 0)]),
    ])])])));
    const tabs = el('ul', 'nav nav-tabs mb-2');
    tabs.setAttribute('role', 'tablist');
    const panes = el('div', 'tab-content');
    keys.forEach((key, i) => {
      const items = allItems.filter((item) => item.status === key);
      const button = el('button', `nav-link${i === 0 ? ' active' : ''}`, [`${STATUS_LABELS[key]} `, el('span', 'badge bg-secondary-lt', [String(items.length)])]);
      button.type = 'button';
      button.setAttribute('role', 'tab');
      button.dataset.bsToggle = 'tab';
      button.dataset.bsTarget = `#map-tab-${key}`;
      const li = el('li', 'nav-item', [button]);
      li.setAttribute('role', 'presentation');
      tabs.append(li);
      const pane = el('div', `tab-pane fade${i === 0 ? ' show active' : ''}`, items.length ? items.map((item) => itemCard(item, key)) : [el('div', 'text-muted small', ['Нет элементов.'])]);
      pane.id = `map-tab-${key}`;
      pane.setAttribute('role', 'tabpanel');
      panes.append(pane);
    });
    previewEl.replaceChildren(cards, tabs, panes);
  }

  async function loadPreview() {
    if (state.loading) return;
    state.requested = true;
    hideAlerts();
    previewEl.replaceChildren();
    emptyEl.classList.add('d-none');
    applyBtn.disabled = true;
    const marketplace = currentMarketplace();
    if (!marketplace) {
      showError('Выберите маркетплейс перед настройкой базового маппинга.');
      state.preview = null;
      updateApplyState();
      return;
    }
    state.loading = true;
    updateApplyState();
    loadingEl.classList.remove('d-none');
    try {
      const payload = await postOk(root.dataset.previewUrl, { marketplace, _token: root.dataset.csrfToken });
      state.preview = payload;
      renderPreview(payload);
    } catch (error) {
      state.preview = null;
      showError(error.message);
    } finally {
      state.loading = false;
      loadingEl.classList.add('d-none');
      updateApplyState();
    }
  }

  modal?.addEventListener('shown.bs.modal', () => {
    if (!state.requested) loadPreview();
  });
  modal?.addEventListener('hidden.bs.modal', () => {
    state.requested = false;
  });

  applyBtn.addEventListener('click', async () => {
    if (applyBtn.disabled) return;
    state.applying = true;
    hideAlerts();
    applyBtn.textContent = 'Применяем...';
    updateApplyState();
    try {
      const data = await postOk(root.dataset.applyUrl, { marketplace: currentMarketplace(), _token: root.dataset.csrfToken });
      const summary = data.summary || {};
      successEl.textContent = `Базовый маппинг применён. Создано: ${summary.created || 0}, дополнено: ${summary.updated || 0}, пропущено: ${summary.skipped || 0}`;
      successEl.classList.remove('d-none');
      setTimeout(() => window.location.reload(), 1000);
    } catch (error) {
      showError(error.message);
    } finally {
      state.applying = false;
      applyBtn.textContent = 'Применить базовый маппинг';
      updateApplyState();
    }
  });
}

export function initCostPlMapping() {
  initDeleteModal();
  initEditModal();
  initDefaultMappingModal();
}
