/**
 * Фильтр «применяется сразу»: форма с data-autosubmit отправляется при изменении
 * select / checkbox / radio внутри неё. Без JS — кнопкой или Enter в поле поиска.
 * Заменяет inline onchange="this.form.submit()".
 */
document.addEventListener('change', (event) => {
    const control = event.target;
    if (!(control instanceof HTMLSelectElement || control instanceof HTMLInputElement)) {
        return;
    }
    if (control instanceof HTMLInputElement && control.type !== 'checkbox' && control.type !== 'radio') {
        return;
    }
    const form = control.form;
    if (!form || !form.hasAttribute('data-autosubmit')) {
        return;
    }
    form.requestSubmit();
});
