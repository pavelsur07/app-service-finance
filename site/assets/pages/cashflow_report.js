/**
 * Кнопка .cf-toggle раскрывает прямых потомков статьи. Строки обоих отчётов идут
 * в порядке обхода дерева, data-level указывает глубину. Сворачивание закрывает всю ветку.
 *
 * Сумма статьи — ссылка a[data-cf-drill] в «Транзакции ДДС» с фильтрами ячейки. Открывается двойным
 * кликом по ячейке; одиночный клик мышью гасится, чтобы сумму можно было выделить. Клавиатура (Enter),
 * касание, Ctrl/Cmd/Shift-клик и средняя кнопка работают как у обычной ссылки.
 */
const COLLAPSED = '▶';
const EXPANDED = '▼';

const setExpanded = (button, expanded) => {
  button.setAttribute('aria-expanded', String(expanded));
  button.textContent = expanded ? EXPANDED : COLLAPSED;
};

const setBranchExpanded = (parent, expand) => {
  const level = Number(parent.dataset.level);
  setExpanded(parent.querySelector('.cf-toggle'), expand);
  for (let row = parent.nextElementSibling; row && Number(row.dataset.level) > level; row = row.nextElementSibling) {
    row.hidden = !expand || Number(row.dataset.level) !== level + 1;
    if (!expand) {
      const childButton = row.querySelector('.cf-toggle');
      if (childButton) setExpanded(childButton, false);
    }
  }
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
  document.querySelectorAll('[data-cashflow-report]').forEach((table) => {
    initDrilldown(table);
    table.addEventListener('click', (event) => {
      const button = event.target.closest('.cf-toggle');
      if (!button) return;
      const parent = button.closest('tr');
      const expand = button.getAttribute('aria-expanded') !== 'true';
      // В ДДС компании одна статья повторяется в таблицах разных валют.
      const rows = parent.dataset.id
        ? document.querySelectorAll(`[data-cashflow-report] tr[data-id="${CSS.escape(parent.dataset.id)}"]`)
        : [parent];
      rows.forEach((row) => setBranchExpanded(row, expand));
    });
  });
}
