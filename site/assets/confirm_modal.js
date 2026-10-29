/**
 * Диалог подтверждения (templates/partials/_confirm_modal.html.twig): один на страницу.
 * При открытии берёт адрес формы, CSRF-токен и тексты из data-confirm-* кнопки-триггера,
 * поэтому разметка не повторяется в каждой строке таблицы. Текст — только textContent.
 */
const triggers = new WeakMap();

document.addEventListener('show.bs.modal', (event) => {
    const modal = event.target;
    const trigger = event.relatedTarget;

    if (!(modal instanceof HTMLElement) || !modal.matches('[data-confirm-modal]')) {
        return;
    }

    if (!(trigger instanceof HTMLElement)) {
        event.preventDefault();

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

    // Запрещённое действие: объясняем причину, отправить форму нельзя ни кнопкой, ни Enter.
    const blocked = 'true' === data.confirmBlocked;
    modal.classList.toggle('vf-confirm--blocked', blocked);
    submit.hidden = blocked;
    modal.querySelector('[data-confirm-cancel]').textContent = blocked ? 'Закрыть' : 'Отмена';

    const link = modal.querySelector('[data-confirm-link]');
    link.hidden = !data.confirmLinkHref;
    link.href = data.confirmLinkHref || '#';
    link.textContent = data.confirmLinkLabel || '';

    // Несколько выходов — у каждой причины запрета свой; адрес и подпись только через свойства.
    const list = modal.querySelector('[data-confirm-links]');
    list.replaceChildren();
    let links = [];
    try {
        links = JSON.parse(data.confirmLinks || '[]');
    } catch {
        links = [];
    }
    links.filter((item) => item && item.href && item.label).forEach((item) => {
        const anchor = document.createElement('a');
        anchor.href = item.href;
        anchor.textContent = item.label;
        const li = document.createElement('li');
        li.append(anchor);
        list.append(li);
    });
    list.hidden = 0 === list.childElementCount;

    // Без JS кнопка остаётся disabled: пустая форма не уйдёт на текущую страницу.
    submit.disabled = blocked;
    triggers.set(modal, trigger);
});

// Фокус на безопасное действие: Enter сразу после открытия не архивирует.
document.addEventListener('shown.bs.modal', (event) => {
    if (event.target instanceof HTMLElement && event.target.matches('[data-confirm-modal]')) {
        event.target.querySelector('[data-bs-dismiss="modal"]')?.focus();
    }
});

// Bootstrap возвращает фокус триггеру, только если тот виден. Пункт закрытого меню скрыт —
// фокус падал на body; возвращаем его на кнопку «⋯» той же строки.
document.addEventListener('hidden.bs.modal', (event) => {
    const modal = event.target;
    if (!(modal instanceof HTMLElement) || !modal.matches('[data-confirm-modal]')) {
        return;
    }

    const trigger = triggers.get(modal);
    triggers.delete(modal);
    modal.querySelector('[data-confirm-submit]').disabled = true;

    if (trigger && !trigger.checkVisibility?.()) {
        trigger.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]')?.focus();
    }
});
