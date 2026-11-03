/**
 * Страницы входа и регистрации — прогрессивное улучшение. Без JS формы отправляются как обычно
 * (серверная валидация — источник истины).
 * Хуки (data-атрибуты, классов JS не касается):
 *   [data-auth-form]                      форма; при submit — состояние загрузки кнопки, фокус на первое невалидное поле
 *   [data-pw-toggle="#id"]                кнопка «глаз»: показать/скрыть пароль (aria-pressed, aria-label)
 *   [data-pw-meter] + [data-pw-input]     индикатор надёжности и список правил (регистрация)
 *   [data-pw-rule="len|case|digit"]       пункты правил; атрибут data-ok="true|false"
 * Правила пароля (как в макете): ≥ 8 символов, строчные+заглавные, цифра. Символ — бонус к «Надёжный».
 */
const RULES = {
  len: (p) => p.length >= 8,
  case: (p) => /[a-zа-яё]/.test(p) && /[A-ZА-ЯЁ]/.test(p),
  digit: (p) => /\d/.test(p),
};
const LABELS = ['', 'Слабый', 'Средний', 'Хороший', 'Надёжный'];
const COLORS = ['var(--muted)', '#b00020', '#c77700', '#1d7a30', '#1d7a30'];

function score(p) {
  if (!p) return 0;
  const met = Object.values(RULES).filter((f) => f(p)).length;
  const sym = /[^\p{L}\d\s]/u.test(p);
  return Math.min(4, met + (sym && met === 3 ? 1 : 0));
}

function initToggle(btn) {
  const input = document.querySelector(btn.dataset.pwToggle);
  if (!input) return;
  btn.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(show));
    btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
  });
}

function initMeter(root) {
  const input = document.querySelector('[data-pw-input]');
  if (!input) return;
  const segs = root.querySelectorAll('[data-pw-seg]');
  const label = root.querySelector('[data-pw-label]');
  const update = () => {
    const p = input.value, sc = score(p);
    segs.forEach((s, i) => { s.style.background = i < sc ? COLORS[sc] : ''; });
    if (label) { label.textContent = LABELS[sc]; label.style.color = COLORS[sc]; }
    root.querySelectorAll('[data-pw-rule]').forEach((li) => {
      const ok = RULES[li.dataset.pwRule](p);
      li.dataset.ok = String(ok);
      li.style.color = ok ? 'var(--ok-text)' : 'var(--muted)';
      const sr = li.querySelector('[data-sr]');
      if (sr) sr.textContent = ok ? ' — выполнено' : ' — не выполнено';
    });
  };
  input.addEventListener('input', update);
  update();
}

function initForm(form) {
  form.addEventListener('submit', () => {
    const btn = form.querySelector('[type=submit]');
    if (btn) { btn.setAttribute('aria-busy', 'true'); btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = btn.dataset.loadingText || 'Подождите…'; }
  });
  // после серверной валидации: фокус на первое поле с aria-invalid=true
  const bad = form.querySelector('[aria-invalid="true"]');
  if (bad) bad.focus();
}

document.querySelectorAll('[data-pw-toggle]').forEach(initToggle);
document.querySelectorAll('[data-pw-meter]').forEach(initMeter);
document.querySelectorAll('[data-auth-form]').forEach(initForm);
