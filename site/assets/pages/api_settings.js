const secret = document.querySelector('[data-api-secret]');
const copyButton = document.querySelector('[data-api-copy]');
const status = document.querySelector('[data-api-copy-status]');

if (secret instanceof HTMLInputElement && copyButton instanceof HTMLButtonElement && status instanceof HTMLElement) {
    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(secret.value);
            status.textContent = 'Ключ скопирован.';
        } catch {
            secret.focus();
            secret.select();
            status.textContent = 'Не удалось скопировать автоматически. Скопируйте выделенный ключ вручную.';
        }
    });
    const clearSecret = () => {
        secret.value = '';
        secret.removeAttribute('value');
        copyButton.disabled = true;
        status.textContent = 'Секрет больше не доступен. Если вы его не сохранили, создайте новый ключ.';
    };
    window.addEventListener('pagehide', clearSecret);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) clearSecret();
    });
}

const checkInput = document.querySelector('input[name="api_key_check[secret]"]');
if (checkInput instanceof HTMLInputElement) {
    const clearCheckInput = () => {
        checkInput.value = '';
        checkInput.removeAttribute('value');
    };
    window.addEventListener('pagehide', clearCheckInput);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) clearCheckInput();
    });
}

const permissionsForm = document.querySelector('form[data-api-permissions]');
if (permissionsForm instanceof HTMLFormElement) {
    permissionsForm.querySelectorAll('[data-api-preset]').forEach((button) => {
        button.addEventListener('click', () => {
            const selected = JSON.parse(button.getAttribute('data-api-preset') ?? '[]');
            if (!Array.isArray(selected) || !selected.every((scope) => typeof scope === 'string')) return;
            permissionsForm.querySelectorAll('input[data-api-scope]').forEach((input) => {
                if (input instanceof HTMLInputElement) input.checked = selected.includes(input.value);
            });
            const presetStatus = permissionsForm.querySelector('[data-api-preset-status]');
            if (presetStatus instanceof HTMLElement) presetStatus.textContent = 'Набор выбран. Нажмите «Сохранить права», чтобы применить изменения.';
        });
    });
}
