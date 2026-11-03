/**
 * Страницы входа и регистрации — прогрессивное улучшение: без JS формы уходят как обычно,
 * серверная валидация — источник истины. Исходник — docs/design-system/components/auth/auth.js.
 *
 * Хуки (data-атрибуты):
 *   [data-auth-form]                 форма: проверка до отправки, фокус на первое невалидное, загрузка кнопки
 *   [data-check="required email minlen checked"] + data-msg-<check>   правила поля и тексты ошибок
 *   [data-err-for="<id поля>"]       контейнер ошибки поля (серверные ошибки выводятся туда же)
 *   [data-pw-toggle="<id поля>"]     кнопка «глаз» (aria-pressed, aria-label)
 *   [data-pw-meter]                  индикатор надёжности; [data-pw-rule="len"][data-min] — правило сервера
 *   [data-loading-text]              текст кнопки при отправке
 */
import '../styles/pages/auth.css';
import './cookie_consent.js';

const ERROR_ICON = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>';
// Не строже серверного Assert::email (Unicode разрешён): только форма «что-то@что-то.зона».
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u;
const LABELS = ['', 'Слабый', 'Средний', 'Хороший', 'Надёжный'];

function fieldError(input) {
    const checks = (input.dataset.check || '').split(/\s+/).filter(Boolean);
    const value = input.type === 'checkbox' ? '' : input.value.trim();
    for (const check of checks) {
        if (check === 'checked' && !input.checked) return input.dataset.msgChecked;
        if (check === 'required' && value === '') return input.dataset.msgRequired;
        if (check === 'email' && value !== '' && !EMAIL_RE.test(value)) return input.dataset.msgEmail;
        if (check === 'minlen' && value !== '' && input.value.length < Number(input.dataset.min || 0)) return input.dataset.msgMinlen;
    }
    return null;
}

function showError(form, input, message) {
    const box = form.querySelector(`[data-err-for="${input.id}"]`);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
    // Невыполненное правило пароля при ошибке поля — красное (AUTH-SPEC §5).
    form.querySelectorAll(`[data-pw-meter="${input.id}"] [data-pw-rule]`).forEach((rule) => {
        if (message && rule.dataset.ok !== 'true') rule.dataset.error = 'true';
        else rule.removeAttribute('data-error');
    });
    if (!box) return;
    box.innerHTML = '';
    if (message) {
        const err = document.createElement('div');
        err.className = 'err';
        err.setAttribute('role', 'alert');
        err.innerHTML = ERROR_ICON;
        const text = document.createElement('span');
        text.textContent = message;
        err.append(text);
        box.append(err);
    }
}

function setBusy(form) {
    const btn = form.querySelector('[type=submit]');
    if (!btn) return;
    btn.dataset.label = btn.textContent.trim();
    btn.setAttribute('aria-busy', 'true');
    btn.disabled = true;
    btn.innerHTML = '<span class="spin" aria-hidden="true"></span>';
    btn.append(document.createTextNode(btn.dataset.loadingText || 'Подождите…'));
}

function resetBusy(form) {
    const btn = form.querySelector('[type=submit][aria-busy="true"]');
    if (!btn) return;
    btn.removeAttribute('aria-busy');
    btn.disabled = false;
    btn.textContent = btn.dataset.label || btn.textContent;
}

function initForm(form) {
    const inputs = Array.from(form.querySelectorAll('[data-check]'));
    let submitted = false;

    // Серверная ошибка (например, «email занят») держится, пока значение поля не изменилось.
    inputs.forEach((input) => {
        if (input.getAttribute('aria-invalid') === 'true' && input.type !== 'checkbox') input.dataset.serverValue = input.value;
    });

    const validate = (input) => {
        const message = fieldError(input);
        if (message === null && input.dataset.serverValue !== undefined && input.dataset.serverValue === input.value) return true;
        showError(form, input, message);
        return message === null;
    };

    inputs.forEach((input) => {
        const event = input.type === 'checkbox' ? 'change' : 'input';
        input.addEventListener(event, () => { if (submitted || input.getAttribute('aria-invalid') === 'true') validate(input); });
        if ((input.dataset.check || '').includes('email')) {
            input.addEventListener('blur', () => { if (input.value.trim() !== '') validate(input); });
        }
    });

    form.addEventListener('submit', (event) => {
        submitted = true;
        const invalid = inputs.filter((input) => !validate(input));
        if (invalid.length > 0) {
            event.preventDefault();
            invalid[0].focus();
            return;
        }
        setBusy(form);
    });

    // Возврат кнопкой «Назад» из bfcache: кнопка не должна остаться заблокированной.
    window.addEventListener('pageshow', () => resetBusy(form));

    // После серверной валидации — фокус на первое невалидное поле.
    const bad = form.querySelector('[aria-invalid="true"]');
    if (bad) bad.focus();
}

function initToggle(btn) {
    const input = document.getElementById(btn.dataset.pwToggle);
    if (!input) return;
    btn.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
        btn.setAttribute('aria-label', show ? 'Скрыть пароль' : 'Показать пароль');
    });
}

function initMeter(meter) {
    const input = document.getElementById(meter.dataset.pwMeter);
    if (!input) return;
    const label = meter.querySelector('[data-pw-label]');
    const rules = Array.from(meter.querySelectorAll('[data-pw-rule]'));

    const update = () => {
        const p = input.value;
        const min = Number(meter.dataset.min || 0);
        let score = 0;
        if (p !== '') {
            const traits = [/\p{Ll}/u.test(p) && /\p{Lu}/u.test(p), /\d/.test(p), /[^\p{L}\d\s]/u.test(p), p.length >= 12];
            // Подсказка, не правило: короче серверного минимума — всегда «слабый».
            score = p.length < min ? 1 : Math.min(4, 1 + traits.filter(Boolean).length);
        }
        meter.dataset.score = String(score);
        if (label) label.textContent = LABELS[score];
        rules.forEach((rule) => {
            const ok = p.length >= Number(rule.dataset.min || 0);
            rule.dataset.ok = String(ok);
            if (ok) rule.removeAttribute('data-error');
            const sr = rule.querySelector('[data-sr]');
            if (sr) sr.textContent = ok ? ' — выполнено' : ' — не выполнено';
        });
    };
    input.addEventListener('input', update);
    update();
}

document.querySelectorAll('[data-pw-toggle]').forEach(initToggle);
document.querySelectorAll('[data-pw-meter]').forEach(initMeter);
document.querySelectorAll('[data-auth-form]').forEach(initForm);
