/**
 * Шапка экрана варианта бюджета (templates/budget/show.html.twig), BUDGET-SPEC §5: название
 * правится на месте по карандашу — Enter сохраняет (POST …/name, JSON), Esc отменяет, ошибка
 * сервера — под полем. Сервер — источник истины: название берётся из ответа.
 */
function initRename(root) {
  const trigger = root.querySelector('[data-budget-rename]');
  const form = root.querySelector('[data-budget-rename-form]');
  if (!trigger || !form) return;
  const title = root.querySelector('[data-budget-name]');
  const crumb = root.querySelector('[data-budget-name-crumb]');
  const input = form.querySelector('[data-budget-rename-input]');
  const error = form.querySelector('[data-budget-rename-error]');
  let saving = false;

  const setError = (text) => {
    error.textContent = text;
    input.classList.toggle('is-invalid', text !== '');
    if (text === '') input.removeAttribute('aria-invalid');
    else input.setAttribute('aria-invalid', 'true');
  };
  const toggle = (editing) => {
    form.hidden = !editing;
    title.hidden = editing;
    trigger.hidden = editing;
  };
  const open = () => {
    input.value = title.textContent.trim();
    setError('');
    toggle(true);
    input.focus();
    input.select();
  };
  const close = () => {
    toggle(false);
    trigger.focus({ preventScroll: true });
  };
  const apply = (oldName, name) => {
    title.textContent = name;
    if (crumb) crumb.textContent = name;
    // Функция замены: `$&` и подобные в названии — обычный текст.
    document.title = document.title.replace(oldName, () => name);
    // Заголовки подтверждений в шапке несут название — обновить, чтобы не показывать старое.
    for (const el of document.querySelectorAll('[data-confirm-title]')) {
      el.dataset.confirmTitle = el.dataset.confirmTitle.replace(`«${oldName}»`, () => `«${name}»`);
    }
  };

  const save = async () => {
    if (saving) return;
    const oldName = title.textContent.trim();
    const name = input.value.trim();
    if (name === '') {
      setError('Введите название варианта');
      return;
    }
    if (name === oldName) {
      close();
      return;
    }
    saving = true;
    input.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(form.dataset.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': form.dataset.token },
        body: JSON.stringify({ name }),
      });
      // Истёкшая сессия: form_login отвечает редиректом на /login, fetch получает HTML 200.
      if (response.status === 401 || (response.redirected && new URL(response.url).pathname.startsWith('/login'))) {
        window.location.assign('/login');
        return;
      }
      const body = await response.json().catch(() => null);
      if (!response.ok || typeof body?.name !== 'string') {
        setError(body?.error?.message ?? 'Не удалось переименовать. Повторите.');
        return;
      }
      apply(oldName, body.name);
      close();
    } catch {
      setError('Нет связи с сервером. Повторите.');
    } finally {
      saving = false;
      input.removeAttribute('aria-busy');
    }
  };

  trigger.addEventListener('click', open);
  form.addEventListener('submit', (event) => {
    event.preventDefault();
    void save();
  });
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close();
    }
  });
  input.addEventListener('input', () => {
    if (input.value.trim() !== '') setError('');
  });
}

export function initBudgetVersion(root = document.querySelector('[data-budget-page="version"]')) {
  if (!root) return;
  initRename(root);
}
