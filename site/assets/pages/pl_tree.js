/**
 * Дерево статей ОПиУ в таблице [data-pl-tree] (templates/finance/report/preview.html.twig,
 * templates/marketplace/pl_preview/index.html.twig): открывается свёрнутым до уровня 1, клик по статье
 * раскрывает её прямых потомков, повторный — сворачивает всю ветку. Кнопки [data-pl-expand-all] /
 * [data-pl-collapse-all] — если есть на странице. Без JS таблица остаётся развёрнутой.
 * Там же переключатель «Колонки» предпросмотра ОПиУ: [data-pl-columns-mode] показывает список
 * [data-pl-columns-for] своего разреза.
 */
function initTree(table) {
  const rows = Array.from(table.querySelectorAll('tr.pl-row'));
  if (!rows.length) return;

  const levelOf = (row) => parseInt(row.getAttribute('data-level'), 10);
  const rowsById = {};
  const childrenMap = {};
  const levelStack = [];
  rows.forEach((row) => {
    const id = row.getAttribute('data-row-id');
    const level = levelOf(row);
    rowsById[id] = row;
    while (levelStack.length && levelStack[levelStack.length - 1].level >= level) levelStack.pop();
    if (levelStack.length) {
      const parentId = levelStack[levelStack.length - 1].id;
      (childrenMap[parentId] = childrenMap[parentId] || []).push(id);
    }
    levelStack.push({ id, level });
  });

  const setExpanded = (row, expanded) => {
    row.querySelector('.pl-toggle[data-target]')?.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  };
  const collapseAll = () => rows.forEach((row) => {
    setExpanded(row, false);
    row.style.display = levelOf(row) > 1 ? 'none' : '';
  });
  const expandAll = () => rows.forEach((row) => {
    setExpanded(row, true);
    row.style.display = '';
  });
  const showDirectChildren = (parentId) => (childrenMap[parentId] || []).forEach((childId) => {
    rowsById[childId].style.display = '';
    setExpanded(rowsById[childId], false);
  });
  const hideDescendants = (parentId) => (childrenMap[parentId] || []).forEach((childId) => {
    rowsById[childId].style.display = 'none';
    setExpanded(rowsById[childId], false);
    hideDescendants(childId);
  });

  collapseAll();

  table.addEventListener('click', (e) => {
    const toggle = e.target.closest('.pl-toggle[data-target]');
    if (!toggle) return;
    // Выделение текста названия — не клик по переключателю.
    const selection = window.getSelection();
    if (selection && !selection.isCollapsed && selection.anchorNode && toggle.contains(selection.anchorNode)) return;

    const targetId = toggle.getAttribute('data-target');
    if (toggle.getAttribute('aria-expanded') === 'true') {
      toggle.setAttribute('aria-expanded', 'false');
      hideDescendants(targetId);
    } else {
      toggle.setAttribute('aria-expanded', 'true');
      showDirectChildren(targetId);
    }
  });

  document.querySelector('[data-pl-expand-all]')?.addEventListener('click', expandAll);
  document.querySelector('[data-pl-collapse-all]')?.addEventListener('click', collapseAll);
}

function initColumnsMode() {
  const mode = document.querySelector('[data-pl-columns-mode]');
  if (!mode) return;
  mode.addEventListener('change', () => {
    document.querySelectorAll('[data-pl-columns-for]').forEach((el) => {
      el.hidden = el.getAttribute('data-pl-columns-for') !== mode.value;
    });
  });
}

export function initPlTree() {
  document.querySelectorAll('[data-pl-tree]').forEach(initTree);
  initColumnsMode();
}
