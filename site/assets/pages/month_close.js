/**
 * «Закрытие месяца» (templates/marketplace/month_close/index.html.twig): проверка готовности
 * этапа и запуск оперативного пересчёта. Адреса API — на корне [data-month-close], CSRF-токен и
 * период — на кнопках [data-preflight] / [data-preliminary-rebuild]. Ответы API — только textContent.
 */
import { apiFetch } from '../api/client';

const el = (tag, className, children = []) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  for (const child of children) node.append(child);
  return node;
};

const MARK = { ok: ['text-success', '✓'], blocking: ['text-danger', '✗'], warning: ['text-warning', '⚠'] };

// Строка деталей проверки по её форме (см. MonthClosePreflightResult::toArray()).
function detailText(item) {
  if (item.orphan === true) return `Без привязки к листингу — ${Number(item.count)} шт.`;
  if (item.marketplace_sku !== undefined && item.marketplace_sku !== null) {
    const supplier = item.supplier_sku ? ` (артикул: ${item.supplier_sku})` : '';
    return `SKU ${item.marketplace_sku}${supplier} — ${Number(item.count)} шт.`;
  }
  if (item.service_name !== undefined) {
    const code = item.category_code ? ` (${item.category_code})` : '';
    return `${item.service_name}${code} — ${Number(item.count)} шт.`;
  }
  if (item.category_name !== undefined) return `${item.category_name} (${Number(item.costs_count)} шт.)`;
  if (item.business_date !== undefined) return `${item.business_date} — ${item.status ?? 'нет статуса'}`;
  return null;
}

function renderChecks(checks) {
  return el('div', 'list-group list-group-flush', checks.map((check) => {
    const [css, mark] = check.passed ? MARK.ok : (check.blocking ? MARK.blocking : MARK.warning);
    const body = [
      el('div', 'fw-medium small', [String(check.label ?? '')]),
      el('div', 'text-muted small', [String(check.message ?? '')]),
    ];
    const details = (Array.isArray(check.details) ? check.details : []).map(detailText).filter((text) => text !== null);
    if (details.length > 0) body.push(el('ul', 'mt-1 mb-0 small text-muted', details.map((text) => el('li', '', [text]))));
    return el('div', 'list-group-item px-0 py-2 d-flex gap-2 align-items-start', [el('span', css, [mark]), el('div', '', body)]);
  }));
}

// Кнопка занята: спиннер и подпись вместо содержимого; возвращает функцию восстановления.
function busy(button, label) {
  const original = [...button.childNodes];
  button.disabled = true;
  button.replaceChildren(el('span', 'spinner-border spinner-border-sm me-1'), label);
  return () => {
    button.disabled = false;
    button.replaceChildren(...original);
  };
}

async function runPreflight(root, button) {
  const stage = button.dataset.stage ?? '';
  const result = root.querySelector(`[data-preflight-result="${stage}"]`);
  const closeButton = root.querySelector(`[data-close-stage="${stage}"]`);
  if (!result) return;
  const restore = busy(button, 'Проверяем...');
  result.hidden = true;
  if (closeButton) closeButton.disabled = true;

  const body = new FormData();
  for (const name of ['marketplace', 'year', 'month', 'stage']) body.append(name, button.dataset[name] ?? '');
  body.append('_token', button.dataset.token ?? '');
  try {
    const data = await apiFetch(root.dataset.preflightUrl, { method: 'POST', body });
    result.replaceChildren(renderChecks(Array.isArray(data.checks) ? data.checks : []));
    if (closeButton) closeButton.disabled = data.can_close !== true;
  } catch {
    result.replaceChildren(el('div', 'alert alert-danger', ['Ошибка проверки. Попробуйте ещё раз.']));
  } finally {
    result.hidden = false;
    restore();
  }
}

async function runPreliminaryRebuild(root, button) {
  const restore = busy(button, 'Запускаем...');
  try {
    await apiFetch(root.dataset.rebuildUrl, {
      method: 'POST',
      body: {
        marketplace: button.dataset.marketplace,
        year: Number(button.dataset.year),
        month: Number(button.dataset.month),
        _token: button.dataset.token,
      },
    });
    window.alert('Запущен пересчёт. Обновите страницу через минуту.');
  } catch (error) {
    window.alert(error.message || 'Не удалось запустить пересчёт.');
  } finally {
    restore();
  }
}

export function initMonthClose() {
  const root = document.querySelector('[data-month-close]');
  if (!(root instanceof HTMLElement)) return;
  root.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-preflight], [data-preliminary-rebuild]') : null;
    if (!(button instanceof HTMLButtonElement) || button.disabled) return;
    if (button.hasAttribute('data-preflight')) {
      runPreflight(root, button);
    } else {
      runPreliminaryRebuild(root, button);
    }
  });
}
