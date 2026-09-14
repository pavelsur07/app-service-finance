import './styles/legacy-app.css';
import './bootstrap.js';
import './project_direction_picker.js';
import './counterparty_picker.js';

// Keep connection verification as a regular POST while preventing duplicate clicks.
document.addEventListener('submit', (event) => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-moysklad-check')) return;
  if (form.dataset.submitting === 'true') {
    event.preventDefault();
    return;
  }
  form.dataset.submitting = 'true';
  form.setAttribute('aria-busy', 'true');
  for (const button of form.querySelectorAll('button[type="submit"]')) {
    button.dataset.originalLabel = button.textContent;
    button.disabled = true;
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('aria-hidden', 'true');
    button.replaceChildren(spinner, document.createTextNode('Проверяем…'));
  }
});

// Back/forward cache may restore the submitted page with its controls disabled.
window.addEventListener('pageshow', () => {
  for (const form of document.querySelectorAll('form[data-moysklad-check]')) {
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');
    for (const button of form.querySelectorAll('button[data-original-label]')) {
      button.disabled = false;
      button.textContent = button.dataset.originalLabel;
      delete button.dataset.originalLabel;
    }
  }
});
