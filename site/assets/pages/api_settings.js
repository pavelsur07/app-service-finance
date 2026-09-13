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
