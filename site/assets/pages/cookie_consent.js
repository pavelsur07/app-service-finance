/**
 * Баннер cookie (AUTH-SPEC §7). Исходник — docs/design-system/components/auth/cookie-consent.js.
 * Хуки: [data-cookie-banner] — корень (по умолчанию hidden); [data-cookie="all|necessary"] — кнопки.
 * Выбор — cookie `vf_cookie_consent` (all | necessary), 180 дней, SameSite=Lax, Secure на https.
 *
 * Аналитика и маркетинговые скрипты не грузятся до «Принять все». Подключать их только так:
 *   document.addEventListener('vf:cookie-consent', (e) => { if (e.detail.level === 'all') loadAnalytics(); });
 * Событие срабатывает и при загрузке страницы, если выбор уже сохранён; скрипт, подключённый позже,
 * читает уровень из <html data-cookie-consent>. Сейчас таких скриптов нет.
 */
const NAME = 'vf_cookie_consent';
const DAYS = 180;

function read() {
    const match = document.cookie.match(new RegExp(`(?:^|; )${NAME}=([^;]*)`));
    return match ? decodeURIComponent(match[1]) : '';
}

function write(level) {
    const expires = new Date(Date.now() + DAYS * 864e5).toUTCString();
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${NAME}=${encodeURIComponent(level)}; expires=${expires}; path=/; SameSite=Lax${secure}`;
}

function emit(level) {
    // Для скриптов, подключённых позже события: уровень согласия читается с <html data-cookie-consent>.
    document.documentElement.dataset.cookieConsent = level;
    document.dispatchEvent(new CustomEvent('vf:cookie-consent', { detail: { level } }));
}

const banner = document.querySelector('[data-cookie-banner]');
const saved = read();
if (saved === 'all' || saved === 'necessary') {
    if (banner) banner.hidden = true;
    emit(saved);
} else if (banner) {
    banner.hidden = false;
    banner.querySelectorAll('[data-cookie]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const level = btn.dataset.cookie === 'all' ? 'all' : 'necessary';
            write(level);
            banner.hidden = true;
            emit(level);
        });
    });
}
