/**
 * «Интеграция с Telegram» (templates/telegram/integration/index.html.twig): [data-copy-text] копирует ссылку,
 * надпись «Скопировано» держится 2 с, затем возвращается исходная. Корень — [data-telegram-integration].
 */
export function initTelegramIntegration() {
  if (!document.querySelector('[data-telegram-integration]')) return;
  document.querySelectorAll('[data-copy-text]').forEach((button) => {
    button.addEventListener('click', () => {
      const text = button.getAttribute('data-copy-text');
      if (!text) return;
      navigator.clipboard.writeText(text).then(() => {
        const originalText = button.textContent;
        button.textContent = 'Скопировано';
        // Возвращаем исходный текст через короткую задержку, чтобы пользователь понял, что копирование прошло.
        setTimeout(() => { button.textContent = originalText; }, 2000);
      });
    });
  });
}
