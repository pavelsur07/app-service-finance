/**
 * Модал «Базовый маппинг продаж и возвратов» / «Настройка статей Ozon»
 * (templates/marketplace/pl_mappings/_default_mapping_modal.html.twig): preview и apply по
 * data-* корня [data-default-sale-mapping-modal] (тело — form-data с marketplace и _token).
 * Таблица preview строится через DOM, данные API — только textContent.
 */
import { apiFetch } from '../api/client';
import { el } from '../dom.js';

const STATUS_CLASS = {
  will_create: 'bg-green-lt',
  skipped_existing: 'bg-secondary-lt',
  missing_pl_category: 'bg-red-lt',
  invalid_target_category: 'bg-red-lt',
};

export function initDefaultSaleMapping() {
  const root = document.querySelector('[data-default-sale-mapping-modal]');
  if (!(root instanceof HTMLElement)) return;
  const modal = root.closest('.modal');
  const { previewUrl, applyUrl, csrfToken, marketplace } = root.dataset;
  const isOzon = marketplace === 'ozon';
  const q = (name) => root.querySelector(`[data-default-sale-mapping-${name}]`);
  const loadingEl = q('loading');
  const previewEl = q('preview');
  const emptyEl = q('empty');
  const errorEl = q('error');
  const successEl = q('success');
  const blockingEl = q('blocking');
  const mismatchEl = q('mismatch');
  const conflictsEl = q('conflicts');
  const hintEl = q('hint');
  const applyBtn = q('apply');
  const state = { preview: null, loading: false, applying: false, requested: false, applied: false };

  const hideAlerts = () => [errorEl, successEl, blockingEl, mismatchEl, conflictsEl].forEach((node) => node.classList.add('d-none'));
  const showError = (message) => {
    errorEl.textContent = message;
    errorEl.classList.remove('d-none');
  };

  // Эндпоинты отвечают 200 с {ok: false, message} на доменную ошибку.
  async function post(url) {
    const body = new FormData();
    body.set('marketplace', marketplace ?? '');
    body.set('_token', csrfToken ?? '');
    const data = await apiFetch(url, { method: 'POST', body });
    if (!data || data.ok === false) throw new Error(data?.message || 'Ошибка запроса. Попробуйте позже.');
    return data;
  }

  function updateApplyState() {
    const summary = state.preview?.summary || {};
    const actionable = summary.will_create || 0;
    const hasBlocking = Boolean(state.preview?.hasBlockingIssues);
    applyBtn.disabled = !state.preview || state.loading || state.applying || state.applied || hasBlocking || actionable === 0;
    if (!state.preview) {
      hintEl.textContent = '';
    } else if (hasBlocking) {
      hintEl.textContent = 'Применение недоступно: проверьте статьи ОПиУ и конфликты источников.';
    } else if (actionable === 0) {
      hintEl.textContent = 'Нет новых правил для применения.';
    } else {
      hintEl.textContent = `Будет создано правил: ${actionable}`;
    }
  }

  function targetCell(item) {
    // У уже настроенного правила целевая категория из конфига не применяется — показываем
    // ту, что реально стоит, иначе строка «не найдена» врёт про исправную настройку.
    if (item.existingPlCategoryName) {
      return [item.existingPlCategoryName, el('div', 'text-muted small', ['настроено вручную'])];
    }
    if (item.plCategoryName) {
      return isOzon ? [item.plCategoryName] : [item.plCategoryName, ' ', el('span', 'text-muted', [`(${item.plCode ?? ''})`])];
    }
    return [el('span', 'text-muted', [`${isOzon ? item.amountSourceLabel ?? '' : item.plCode ?? ''} — не найдена`])];
  }

  function row(item) {
    const source = [el('div', '', [item.amountSourceLabel ?? ''])];
    if (!isOzon) source.push(el('div', 'text-muted small', [el('code', '', [item.amountSource ?? ''])]));
    const sign = [item.isNegative ? el('span', 'badge bg-orange-lt', ['минус']) : el('span', 'text-muted', ['плюс'])];
    if (item.signMismatch) sign.push(el('div', 'text-danger small', [`ожидается «${item.expectedNegative ? 'минус' : 'плюс'}»`]));
    return el('tr', '', [
      el('td', '', [item.operationType === 'return' ? 'Возврат' : 'Продажа']),
      el('td', '', source),
      el('td', '', targetCell(item)),
      el('td', '', sign),
      el('td', '', [el('span', `badge ${STATUS_CLASS[item.status] || 'bg-secondary-lt'}`, [item.statusLabel ?? '']), el('div', 'text-muted small', [item.message ?? ''])]),
    ]);
  }

  function renderPreview(payload) {
    previewEl.replaceChildren();
    emptyEl.classList.add('d-none');
    const items = Array.isArray(payload.items) ? payload.items : [];
    if (items.length === 0) {
      emptyEl.classList.remove('d-none');
      return;
    }
    if (payload.hasBlockingIssues) blockingEl.classList.remove('d-none');
    const warnings = Array.isArray(payload.warnings) ? payload.warnings : [];
    if (warnings.length > 0) {
      conflictsEl.textContent = isOzon
        ? 'Выручка с СПП завышена: в статьи включены начисления и реализация Ozon. Закройте это окно и проверьте подсказку в блоке настройки Ozon на странице.'
        : warnings.join(' ');
      conflictsEl.classList.remove('d-none');
    }
    const mismatched = items.filter((item) => item.signMismatch).length;
    if (mismatched > 0) {
      mismatchEl.textContent = `Настроенных правил со знаком, противоречащим базовому: ${mismatched}. Автонастройка их не меняет — проверьте вручную: неверный знак у возврата прибавляет его к выручке вместо вычитания.`;
      mismatchEl.classList.remove('d-none');
    }
    const head = el('tr', '', ['Операция', isOzon ? 'Откуда берём сумму' : 'Источник суммы', isOzon ? 'Статья ОПиУ' : 'Категория ОПиУ', 'Знак', 'Статус'].map((text) => el('th', '', [text])));
    previewEl.replaceChildren(el('div', 'table-responsive', [el('table', 'table table-sm table-vcenter', [el('thead', '', [head]), el('tbody', '', items.map(row))])]));
  }

  async function loadPreview() {
    if (state.loading) return;
    state.requested = true;
    hideAlerts();
    previewEl.replaceChildren();
    emptyEl.classList.add('d-none');
    state.loading = true;
    updateApplyState();
    loadingEl.classList.remove('d-none');
    try {
      const payload = await post(previewUrl);
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
    applyBtn.textContent = 'Применяем…';
    updateApplyState();
    try {
      const data = await post(applyUrl);
      const summary = data.summary || {};
      successEl.textContent = `Готово. Создано правил: ${summary.created || 0}, пропущено: ${summary.skipped || 0}`;
      successEl.classList.remove('d-none');
      // До перезагрузки кнопку больше не оживляем: повторный apply отчитался бы «создано 0».
      state.applied = true;
      setTimeout(() => window.location.reload(), 1000);
    } catch (error) {
      showError(error.message);
    } finally {
      state.applying = false;
      applyBtn.textContent = isOzon ? 'Применить настройку Ozon' : 'Применить базовый маппинг';
      updateApplyState();
    }
  });
}
