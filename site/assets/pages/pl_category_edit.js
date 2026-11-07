/**
 * Редактор статьи ОПиУ (templates/pl_category/edit.html.twig): бейдж и подсказка по типу
 * строки, «Вид расхода» только для потока EXPENSE, копирование кода, вставка кода статьи
 * в формулу по кнопке. Перед submit отключённые поля включаются, чтобы уйти на сервер.
 */
export function initPlCategoryEdit() {
  const form = document.querySelector('[data-pl-category-form]');
  if (!form || form.dataset.plCategoryBound === '1') return;

  const page = form.closest('.page-body') ?? document;
  const type = form.querySelector('[data-pl-category-type]');
  const flow = form.querySelector('[data-pl-category-flow]');
  const expenseType = form.querySelector('[data-pl-category-expense-type]');
  const formula = form.querySelector('[data-pl-category-formula]');
  const hint = form.querySelector('[data-pl-category-formula-hint]');
  const badges = form.querySelectorAll('[data-pl-category-badge]');

  const syncType = () => {
    if (!type) return;
    const isLeaf = type.value === 'LEAF_INPUT';
    if (formula) formula.disabled = isLeaf;
    if (hint) hint.textContent = isLeaf ? (hint.dataset.hintLeaf ?? '') : (hint.dataset.hintFormula ?? '');
    const known = ['KPI', 'SUBTOTAL'].includes(type.value) ? type.value : 'LEAF_INPUT';
    badges.forEach((badge) => { badge.hidden = badge.dataset.plCategoryBadge !== known; });
  };
  const syncFlow = () => {
    if (flow && expenseType) expenseType.disabled = flow.value !== 'EXPENSE';
  };

  type?.addEventListener('change', syncType);
  flow?.addEventListener('change', syncFlow);

  form.addEventListener('click', async (event) => {
    const copyButton = event.target.closest('[data-copy-target]');
    if (!copyButton) return;
    const input = document.querySelector(copyButton.dataset.copyTarget);
    if (!input || !input.value) return;
    try {
      await navigator.clipboard.writeText(input.value);
      const feedback = document.getElementById(copyButton.dataset.copyFeedback);
      if (feedback) {
        feedback.classList.remove('d-none');
        setTimeout(() => feedback.classList.add('d-none'), 1400);
      }
    } catch {
      // буфер обмена недоступен — молча
    }
  });

  page.addEventListener('click', (event) => {
    const button = event.target.closest('[data-insert-code]');
    if (!button) return;
    const textarea = document.querySelector(button.dataset.insertTarget);
    if (!textarea) return;
    const value = button.dataset.insertCode;
    const start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
    const end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length;
    textarea.value = textarea.value.slice(0, start) + value + textarea.value.slice(end);
    const cursor = start + value.length;
    textarea.focus();
    textarea.setSelectionRange(cursor, cursor);
  });

  form.addEventListener('submit', () => {
    if (formula) formula.disabled = false;
    if (expenseType) expenseType.disabled = false;
  });

  form.dataset.plCategoryBound = '1';
  syncType();
  syncFlow();
}
