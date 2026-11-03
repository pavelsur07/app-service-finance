/**
 * Баннер cookie. Хуки: [data-cookie-banner] корень; [data-cookie="all"] / [data-cookie="necessary"] кнопки.
 * Выбор хранится в cookie `vf_cookie_consent` (all | necessary), 180 дней, SameSite=Lax, Secure на https.
 * Аналитика и маркетинговые скрипты НЕ грузятся до согласия «all»: подпишись на событие
 *   document.addEventListener('vf:cookie-consent', (e) => { if (e.detail.level === 'all') loadAnalytics(); });
 * Событие также срабатывает при загрузке страницы, если выбор уже сохранён.
 */
const NAME = 'vf_cookie_consent';
const DAYS = 180;

function read() {
  const m = document.cookie.match(new RegExp('(?:^|; )' + NAME + '=([^;]*)'));
  return m ? decodeURIComponent(m[1]) : '';
}
function write(level) {
  const exp = new Date(Date.now() + DAYS * 864e5).toUTCString();
  const secure = location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = NAME + '=' + encodeURIComponent(level) + '; expires=' + exp + '; path=/; SameSite=Lax' + secure;
}
function emit(level) {
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
      write(level); banner.hidden = true; emit(level);
    });
  });
}
