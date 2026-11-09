/**
 * Отчёт ДДС (templates/report/cashflow.html.twig): кнопка .cf-toggle статьи раскрывает прямых потомков
 * (tr[data-parent]), повторный клик сворачивает всю ветку. Состояние — символ кнопки ▶/▼.
 * Корень — [data-cashflow-report].
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

export function initCashflowReport() {
  if (!document.querySelector('[data-cashflow-report]')) return;
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
