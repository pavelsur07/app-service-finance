/**
 * Операции ДДС (templates/transaction/index.html.twig): поиск в поповерах строки фильтров,
 * массовое удаление выбранных строк, модал «Автоправило» (HTML с сервера, AJAX-«Применить»)
 * и «Создать на основании» (документ ОПиУ: тихое создание или подтверждение без маппинга).
 * Корень — [data-cash-transactions] с data-csrf-token; данные сервера — только textContent.
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';
import { el } from '../dom.js';

const modalOf = (node) => bootstrapModal()?.getOrCreateInstance(node);
const closeButton = (variant) => {
  const button = el('button', `btn ${variant}`, ['Закрыть']);
  button.type = 'button';
  button.dataset.bsDismiss = 'modal';
  return button;
};
const alertBox = (variant, text) => el('div', `alert ${variant} mb-2`, [text]);

// Поповеры строки фильтров (_filter_bar.html.twig): открытие/закрытие, Esc и стрелки — dropdown Bootstrap.
// Здесь только фокус при открытии, поиск по списку и переход из поиска в список стрелкой вниз.
const initPopSearch = (root) => {
  root.querySelectorAll('[data-ops-pop]').forEach((pop) => {
    const toggle = pop.parentElement.querySelector('[data-bs-toggle="dropdown"]');
    const search = pop.querySelector('[data-ops-pop-search]');
    const items = Array.from(pop.querySelectorAll('[data-ops-pop-item]'));
    const empty = pop.querySelector('[data-ops-pop-empty]');

    toggle?.addEventListener('shown.bs.dropdown', () => {
      const target = search ?? pop.querySelector('input:not([type="hidden"])') ?? pop.querySelector('[aria-current="true"]') ?? pop.querySelector('.dropdown-item');
      target?.focus();
    });
    if (!search) return;

    search.addEventListener('input', () => {
      const needle = search.value.trim().toLowerCase();
      let shown = 0;
      items.forEach((item) => {
        const match = needle === '' || item.dataset.opsPopItem.includes(needle);
        item.hidden = !match;
        shown += match ? 1 : 0;
      });
      if (empty) empty.hidden = shown > 0;
    });
    search.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowDown') return;
      event.preventDefault();
      const first = search.value.trim() === '' ? pop.querySelector('.dropdown-item') : pop.querySelector('[data-ops-pop-item]:not([hidden])');
      first?.focus();
    });
  });
};

const initBulkDelete = (root) => {
  const selectAll = root.querySelector('[data-select-all]');
  const rowBoxes = Array.from(root.querySelectorAll('.js-cash-transaction-select'));
  const deleteButton = root.querySelector('[data-bulk-delete]');
  const deleteLabel = root.querySelector('[data-bulk-delete-label]');
  const modal = document.querySelector('[data-bulk-delete-modal]');
  const modalCount = modal?.querySelector('[data-bulk-delete-count]');
  const form = modal?.querySelector('[data-bulk-delete-form]');
  const idsContainer = modal?.querySelector('[data-bulk-delete-ids]');
  const summaryBar = root.querySelector('[data-ops-summary]');
  const selectionBar = root.querySelector('[data-ops-selection]');
  const selectionCount = root.querySelector('[data-ops-selection-count]');
  if (!selectAll || !deleteButton || !deleteLabel || !modal || !modalCount || !form || !idsContainer) return;

  const selected = () => rowBoxes.filter((cb) => cb.checked);
  const renderSelection = () => {
    const count = selected().length;
    selectAll.checked = rowBoxes.length > 0 && count === rowBoxes.length;
    selectAll.indeterminate = count > 0 && count < rowBoxes.length;
    deleteButton.disabled = count === 0;
    deleteLabel.textContent = count > 0 ? `Удалить (${count})` : 'Удалить';
    modalCount.textContent = String(count);
    // Панель выбора заменяет строку «Найдено N», выбранные строки подсвечены (DS-Table).
    if (summaryBar && selectionBar && selectionCount) {
      summaryBar.hidden = count > 0;
      selectionBar.hidden = count === 0;
      selectionCount.textContent = `Выбрано: ${count}`;
    }
    rowBoxes.forEach((cb) => cb.closest('tr')?.classList.toggle('table-active', cb.checked));
  };
  const renderHiddenIds = () => {
    idsContainer.replaceChildren(...selected().map((cb) => {
      const input = el('input');
      input.type = 'hidden';
      input.name = 'transaction_ids[]';
      input.value = cb.value;
      return input;
    }));
  };
  const setAll = (checked) => {
    rowBoxes.forEach((cb) => { cb.checked = checked; });
    renderSelection();
  };

  selectAll.addEventListener('change', () => setAll(selectAll.checked));
  rowBoxes.forEach((cb) => cb.addEventListener('change', renderSelection));
  root.querySelector('[data-ops-clear-selection]')?.addEventListener('click', () => setAll(false));
  modal.addEventListener('show.bs.modal', renderHiddenIds);
  form.addEventListener('submit', renderHiddenIds);
  renderSelection();
};

const initAutoRule = (root) => {
  const modal = document.querySelector('[data-auto-rule-modal]');
  const body = modal?.querySelector('[data-auto-rule-body]');
  if (!body) return;

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-auto-rule]');
    if (!button) return;
    event.preventDefault();
    body.replaceChildren(el('div', 'text-muted', ['Загрузка…']));
    modalOf(modal)?.show();
    try {
      const response = await fetch(button.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      // Тело модалки — HTML с сервера (cash_transaction_auto_rule/_auto_rule_modal_body), как и раньше.
      body.innerHTML = await response.text();
    } catch {
      body.replaceChildren(el('div', 'text-danger', ['Ошибка загрузки']));
    }
  });

  // «Применить» внутри модалки — один обработчик на документ: раньше он навешивался на каждый клик
  // по кнопке, и один submit уходил несколькими POST по одной форме (GlitchTip #414).
  body.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-auto-rule-apply]');
    if (!form) return;
    event.preventDefault();
    // Повторный submit, пока первый запрос в полёте (двойной клик, Enter), игнорируем.
    if (form.dataset.submitting === '1') return;
    form.dataset.submitting = '1';
    form.querySelectorAll('[type="submit"]').forEach((button) => { button.disabled = true; });

    try {
      const data = await apiFetch(form.action, { method: 'POST', body: new FormData(form) });
      if (data.ok) {
        const details = el('div', '', ['Правило: ', el('strong', '', [data.ruleName ?? '']), ` (${data.action ?? ''})`]);
        body.replaceChildren(
          alertBox('alert-success', `Применено: ${data.changed ? 'да' : 'без изменений'}`),
          details,
          el('div', 'mt-3 text-end', [closeButton('btn-primary')]),
        );
        return;
      }
      body.replaceChildren(alertBox('alert-warning', data.message ?? 'Не найдено'), el('div', 'mt-3 text-end', [closeButton('btn-secondary')]));
    } catch (e) {
      // Сервер с JSON-ошибкой (403 — токен) показывается как раньше предупреждением; сеть — ошибкой.
      const variant = e.status ? 'alert-warning' : 'alert-danger';
      const text = e.status ? e.message : 'Не удалось обработать запрос. Попробуйте ещё раз.';
      body.replaceChildren(alertBox(variant, text), el('div', 'mt-3 text-end', [closeButton('btn-secondary')]));
    }
  });
};

const initCreateFrom = (root) => {
  const modal = document.querySelector('[data-create-from-modal]');
  const form = modal?.querySelector('[data-create-from-form]');
  if (!form) return;
  const warning = modal.querySelector('[data-create-from-body]');
  const csrfToken = root.dataset.csrfToken ?? '';

  const showFlash = (message, variant = 'alert-success') => {
    root.parentElement.querySelectorAll('[data-create-from-flash]').forEach((node) => node.remove());
    const close = el('button', 'btn-close');
    close.type = 'button';
    close.dataset.bsDismiss = 'alert';
    const flash = el('div', `alert ${variant} alert-dismissible fade show mb-3`, [el('span', '', [message]), close]);
    flash.dataset.createFromFlash = '';
    root.before(flash);
  };
  const updateRowIcon = (txId, state) => {
    const cell = root.querySelector(`[data-tx-id="${CSS.escape(txId)}"]`);
    if (!cell) return;
    const icon = state === 'success'
      ? el('i', 'ti ti-file-check text-success')
      : el('i', 'ti ti-alert-triangle text-warning');
    icon.title = state === 'success' ? 'Документы ОПиУ созданы на всю сумму' : 'Документ создан без полного маппинга категорий ОПиУ';
    cell.replaceChildren(icon);
  };
  const setDisabled = (button, disabled) => {
    button.classList.toggle('disabled', disabled);
    button.disabled = disabled;
  };
  const disableRowButton = (txId) => {
    const button = root.querySelector(`[data-create-from][data-transaction-id="${CSS.escape(txId)}"]`);
    if (button) setDisabled(button, true);
  };
  const postForm = (url, fields) => {
    const body = new FormData();
    Object.entries(fields).forEach(([name, value]) => body.append(name, value));
    return apiFetch(url, { method: 'POST', body });
  };

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-create-from]');
    if (!button || button.classList.contains('disabled')) return;
    event.preventDefault();
    // Блокируем немедленно, до запроса — защита от двойного клика.
    setDisabled(button, true);
    const url = button.dataset.url;
    const txId = button.dataset.transactionId;

    let data;
    try {
      data = await postForm(url, { _token: csrfToken, confirmed: '0' });
    } catch (e) {
      setDisabled(button, false);
      showFlash(e.status ? `Ошибка: ${e.message}` : 'Ошибка: не удалось выполнить запрос.', 'alert-danger');
      return;
    }
    if (data.needsConfirmation) {
      // Документ ещё не создан — кнопка снова активна на случай закрытия модалки без подтверждения.
      setDisabled(button, false);
      warning.textContent = data.warningMessage;
      form.action = url;
      form.elements._token.value = csrfToken;
      form.dataset.txId = txId;
      modalOf(modal)?.show();
      return;
    }
    // Сценарий A — тихое создание.
    if (data.success) {
      showFlash(data.flash ?? 'Документ ОПиУ создан.');
      updateRowIcon(txId, 'success');
      disableRowButton(txId);
    }
  });

  // Сценарий B — подтверждение создания без маппинга.
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const txId = form.dataset.txId;
    let data;
    try {
      data = await apiFetch(form.action, { method: 'POST', body: new FormData(form) });
    } catch (e) {
      modalOf(modal)?.hide();
      showFlash(e.status ? `Ошибка: ${e.message}` : 'Ошибка: не удалось создать документ.', 'alert-danger');
      return;
    }
    modalOf(modal)?.hide();
    if (data.redirect) {
      updateRowIcon(txId, 'warning');
      disableRowButton(txId);
      window.open(data.redirect, '_blank');
    }
  });
};

export const initCashTransactions = () => {
  const root = document.querySelector('[data-cash-transactions]');
  if (!root) return;
  initPopSearch(root);
  initBulkDelete(root);
  initAutoRule(root);
  initCreateFrom(root);
};
