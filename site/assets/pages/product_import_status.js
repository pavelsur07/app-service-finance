/**
 * Статус импорта товаров (templates/catalog/product/import_status.html.twig): пока импорт
 * pending/processing, опрашивает data-status-url раз в 2 с и по завершении рисует итог.
 * Данные API (в том числе сообщения ошибок из файла) — только через textContent.
 */
import { apiFetch } from '../api/client';

const LABELS = {
  pending: { text: 'В очереди', css: 'bg-secondary' },
  processing: { text: 'Обрабатывается', css: 'bg-warning' },
  done: { text: 'Завершён', css: 'bg-success' },
  failed: { text: 'Ошибка', css: 'bg-danger' },
};

const el = (tag, className, children = []) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  for (const child of children) node.append(child);
  return node;
};
const link = (href, className, text) => {
  const a = el('a', className, [text]);
  a.href = href;
  return a;
};

function stat(value, css, caption) {
  return el('div', 'col-4', [el('div', 'card card-sm', [el('div', 'card-body text-center', [
    el('div', `h1 mb-0 ${css}`.trim(), [String(value ?? 0)]),
    el('div', 'text-muted', [caption]),
  ])])]);
}

function errorsTable(errors) {
  const rows = errors.map((e) => el('tr', '', [
    el('td', '', [String(e.row ?? '')]),
    el('td', '', [el('span', `badge ${e.reason === 'duplicate' ? 'bg-warning-lt' : 'bg-danger-lt'}`, [e.reason === 'duplicate' ? 'Дубль' : 'Ошибка'])]),
    el('td', '', [String(e.message ?? e.error ?? '')]),
  ]));
  const thead = el('thead', '', [el('tr', '', [el('th', '', ['Строка']), el('th', '', ['Причина']), el('th', '', ['Сообщение'])])]);
  return el('div', 'mt-3', [
    el('h4', 'mb-2', [`Пропущенные строки (${errors.length})`]),
    el('div', 'table-responsive', [el('table', 'table table-sm', [thead, el('tbody', '', rows)])]),
  ]);
}

function renderDone(root, data) {
  const errors = Array.isArray(data.errors) ? data.errors : [];
  const children = [el('div', 'row g-3 mb-3', [
    stat(data.rowsCreated, 'text-success', 'Создано'),
    stat(data.rowsSkipped, 'text-warning', 'Пропущено'),
    stat(data.rowsTotal, '', 'Всего строк'),
  ])];
  if (errors.length > 0) children.push(errorsTable(errors));
  children.push(el('div', 'd-flex gap-2 mt-3', [
    link(root.dataset.importUrl, 'btn btn-outline-primary btn-sm', 'Загрузить ещё один файл'),
    link(root.dataset.productsUrl, 'btn btn-primary btn-sm', 'К списку товаров'),
  ]));
  root.replaceChildren(...children);
}

function renderFailed(root, data) {
  const errors = Array.isArray(data.errors) ? data.errors : [];
  const message = errors[0]?.error || 'Неизвестная ошибка';
  root.replaceChildren(
    el('div', 'alert alert-danger', [el('h4', 'alert-title', ['Импорт завершился с ошибкой']), el('div', 'text-muted', [String(message)])]),
    link(root.dataset.importUrl, 'btn btn-outline-primary btn-sm mt-2', 'Попробовать снова'),
  );
}

export function initProductImportStatus() {
  const root = document.querySelector('[data-import-status][data-status-url]');
  if (!root || root.dataset.importStatusBound === '1') return;
  root.dataset.importStatusBound = '1';
  const badge = document.querySelector('[data-import-status-badge]');
  const intervalMs = Number(root.dataset.pollInterval) || 2000;

  const updateBadge = (status) => {
    if (!badge) return;
    const label = LABELS[status] || { text: String(status), css: 'bg-secondary' };
    badge.className = `badge ${label.css}`;
    badge.textContent = label.text;
  };

  const timer = setInterval(async () => {
    let data;
    try {
      data = await apiFetch(root.dataset.statusUrl);
    } catch {
      return; // сеть или 5xx — продолжаем опрос
    }
    updateBadge(data.status);
    if (data.status === 'done') {
      clearInterval(timer);
      renderDone(root, data);
    } else if (data.status === 'failed') {
      clearInterval(timer);
      renderFailed(root, data);
    }
  }, intervalMs);
}
