/**
 * Журнал документов ОПиУ (templates/document/index.html.twig): модал удаления получает action, CSRF-токен
 * и название с пункта меню; без них не открывается. После закрытия форма сбрасывается, фокус
 * возвращается на кнопку меню строки. Корень — #document-delete-modal[data-document-delete-modal].
 */
const DEFAULT_LABEL = 'Выбранный документ';

export function initDocumentDelete() {
  const modal = document.querySelector('[data-document-delete-modal]');
  if (!modal) return;
  const form = document.getElementById('document-delete-form');
  const token = document.getElementById('document-delete-token');
  const label = document.getElementById('document-delete-label');
  const submit = document.getElementById('document-delete-submit');
  let lastTrigger = null;

  modal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    const action = trigger?.getAttribute('data-delete-action');
    const csrfToken = trigger?.getAttribute('data-delete-token');
    if (!action || !csrfToken) {
      event.preventDefault();
      return;
    }
    form.action = action;
    token.value = csrfToken;
    label.textContent = trigger.getAttribute('data-delete-label') || DEFAULT_LABEL;
    submit.disabled = false;
    lastTrigger = trigger;
  });

  modal.addEventListener('hidden.bs.modal', () => {
    const dropdownToggle = lastTrigger?.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]');
    form.removeAttribute('action');
    token.value = '';
    label.textContent = DEFAULT_LABEL;
    submit.disabled = true;
    dropdownToggle?.focus();
    lastTrigger = null;
  });

  form.addEventListener('submit', () => {
    submit.disabled = true;
  });
}
