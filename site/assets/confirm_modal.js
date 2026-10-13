/**
 * Диалог подтверждения (templates/partials/_confirm_modal.html.twig): один на страницу.
 * При открытии берёт адрес формы, CSRF-токен и тексты из data-confirm-* кнопки-триггера,
 * поэтому разметка не повторяется в каждой строке таблицы. Текст — только textContent.
 */
document.addEventListener('show.bs.modal', (event) => {
    const modal = event.target;
    const trigger = event.relatedTarget;

    if (!(modal instanceof HTMLElement) || !modal.matches('[data-confirm-modal]') || !(trigger instanceof HTMLElement)) {
        return;
    }

    const data = trigger.dataset;
    const form = modal.querySelector('[data-confirm-form]');
    const submit = modal.querySelector('[data-confirm-submit]');

    if (!form || !submit || !data.confirmAction || !data.confirmToken) {
        // Без адреса и токена форма ушла бы на текущую страницу — не открываем.
        event.preventDefault();

        return;
    }

    form.action = data.confirmAction;
    modal.querySelector('[data-confirm-token]').value = data.confirmToken;
    modal.querySelector('[data-confirm-title]').textContent = data.confirmTitle || 'Подтвердите действие';
    modal.querySelector('[data-confirm-body]').textContent = data.confirmBody || '';
    submit.textContent = data.confirmSubmit || 'Подтвердить';
    submit.classList.toggle('btn-danger', 'danger' === data.confirmVariant);
    submit.classList.toggle('btn-primary', 'danger' !== data.confirmVariant);
});

// Фокус на безопасное действие: Enter сразу после открытия не архивирует.
document.addEventListener('shown.bs.modal', (event) => {
    if (event.target instanceof HTMLElement && event.target.matches('[data-confirm-modal]')) {
        event.target.querySelector('[data-bs-dismiss="modal"]')?.focus();
    }
});
