/**
 * Форма с data-busy-submit (или data-moysklad-check): после отправки кнопки submit
 * блокируются и показывают спиннер с подписью data-busy-label («Проверяем…» по умолчанию),
 * повторная отправка подавляется. Остаётся обычным POST без JS.
 */
const SELECTOR = 'form[data-busy-submit], form[data-moysklad-check]';

document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.matches(SELECTOR)) return;
  if (form.dataset.submitting === 'true') {
    event.preventDefault();
    return;
  }
  form.dataset.submitting = 'true';
  form.setAttribute('aria-busy', 'true');
  const label = form.dataset.busyLabel || 'Проверяем…';
  for (const button of form.querySelectorAll('button[type="submit"]')) {
    button.dataset.originalLabel = button.textContent;
    button.disabled = true;
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('aria-hidden', 'true');
    button.replaceChildren(spinner, document.createTextNode(label));
  }
});

// Back/forward cache may restore the submitted page with its controls disabled.
window.addEventListener('pageshow', () => {
  for (const form of document.querySelectorAll(SELECTOR)) {
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');
    for (const button of form.querySelectorAll('button[data-original-label]')) {
      button.disabled = false;
      button.textContent = button.dataset.originalLabel;
      delete button.dataset.originalLabel;
    }
  }
});
