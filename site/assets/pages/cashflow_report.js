/**
 * Отчёт ДДС (templates/report/cashflow.html.twig): кнопка .cf-toggle статьи раскрывает прямых потомков
 * (tr[data-parent]), повторный клик сворачивает всю ветку. Состояние — символ кнопки ▶/▼.
 * Корень — [data-cashflow-report].
 *
 * Сумма статьи — ссылка a[data-cf-drill] в «Транзакции ДДС» с фильтрами ячейки. Открывается двойным
 * кликом по ячейке; одиночный клик мышью гасится, чтобы сумму можно было выделить. Клавиатура (Enter),
 * касание, Ctrl/Cmd/Shift-клик и средняя кнопка работают как у обычной ссылки.
 */
const COLLAPSED = '▶';
const EXPANDED = '▼';

const showDirectChildren = (parentId) => {
  document.querySelectorAll(`tr[data-parent="${parentId}"]`).forEach((row) => {
    row.style.display = '';
    const toggle = row.querySelector('.cf-toggle');
    if (toggle) toggle.textContent = COLLAPSED;
  });
};

const hideDescendants = (parentId) => {
  document.querySelectorAll(`tr[data-parent="${parentId}"]`).forEach((row) => {
    row.style.display = 'none';
    const toggle = row.querySelector('.cf-toggle');
    if (toggle) toggle.textContent = COLLAPSED;
    hideDescendants(row.getAttribute('data-id'));
  });
};

const isPlainMouseClick = (event) => event.button === 0 && event.detail > 0
  && event.pointerType !== 'touch' && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;

const initDrilldown = (table) => {
  table.addEventListener('click', (event) => {
    if (event.target.closest('a[data-cf-drill]') && isPlainMouseClick(event)) event.preventDefault();
  });
  table.addEventListener('dblclick', (event) => {
    // Ctrl/Cmd-клик ссылка уже открыла в новой вкладке сама — второй раз не открываем.
    if (event.ctrlKey || event.metaKey || event.shiftKey) return;
    const link = event.target.closest('td')?.querySelector('a[data-cf-drill]');
    if (link) window.location.assign(link.href);
  });
};

export function initCashflowReport() {
  if (!document.querySelector('[data-cashflow-report]')) return;
  document.querySelectorAll('[data-cashflow-report]').forEach(initDrilldown);
  document.querySelectorAll('.cf-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const parentId = btn.getAttribute('data-target');
      if (btn.textContent.trim() === EXPANDED) {
        btn.textContent = COLLAPSED;
        hideDescendants(parentId);
      } else {
        btn.textContent = EXPANDED;
        showDirectChildren(parentId);
      }
    });
  });
}
