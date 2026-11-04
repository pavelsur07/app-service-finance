/**
 * Экран «Варианты» бюджета ОПиУ (templates/budget/index.html.twig), BUDGET-SPEC §4:
 * переключатель года, окно «Новый вариант» (способ, счётчик названия, «Создаю…»,
 * открытие из «Копировать» и пустого года), свёртка архива. Сервер — источник истины:
 * здесь только подсказки до отправки.
 */
const NAME_MAX = 120;

function bootstrapModal(modal) {
  // Tabler 1.2 кладёт Bootstrap в window.tabler.bootstrap; base.html.twig ставит алиас window.bootstrap.
  const bs = window.bootstrap || window.tabler?.bootstrap || null;
  return bs?.Modal ? bs.Modal.getOrCreateInstance(modal) : null;
}

function initNewVersion(root) {
  const modal = root.querySelector('[data-budget-new-modal]');
  if (!modal) return;
  const form = modal.querySelector('[data-budget-new-form]');
  const name = form.querySelector('[data-budget-new-name]');
  const nameError = form.querySelector('[data-budget-new-name-error]');
  const counter = form.querySelector('[data-budget-new-count]');
  const source = form.querySelector('[data-budget-new-source]');
  const submit = form.querySelector('[data-budget-new-submit]');
  const methods = Array.from(form.querySelectorAll('[data-budget-method]'));
  const panels = Array.from(form.querySelectorAll('[data-budget-method-panel]'));
  let opener = null;

  const currentMethod = () => methods.find((input) => input.checked)?.value ?? 'empty';
  const showPanel = () => {
    const method = currentMethod();
    for (const panel of panels) panel.hidden = panel.dataset.budgetMethodPanel !== method;
  };
  const updateCounter = () => {
    counter.textContent = `${name.value.length}/${NAME_MAX}`;
  };
  const setNameError = (text) => {
    nameError.textContent = text;
    name.classList.toggle('is-invalid', text !== '');
  };
  const setMethod = (method) => {
    const input = methods.find((candidate) => candidate.value === method && !candidate.disabled);
    if (input) input.checked = true;
    showPanel();
  };
  const open = (method, trigger) => {
    opener = trigger;
    if (method) setMethod(method);
    bootstrapModal(modal)?.show();
  };

  for (const input of methods) input.addEventListener('change', showPanel);
  name.addEventListener('input', () => {
    updateCounter();
    if (name.value.trim() !== '') setNameError('');
  });

  root.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-budget-new], [data-budget-copy]');
    if (!trigger || trigger.disabled) return;
    if (trigger.hasAttribute('data-budget-copy')) {
      if (source) source.value = trigger.dataset.budgetCopy;
      open('copy', trigger);
      return;
    }
    open(trigger.dataset.budgetNew, trigger);
  });

  modal.addEventListener('shown.bs.modal', () => name.focus());
  modal.addEventListener('hidden.bs.modal', () => {
    // Фокус — на кнопку, открывшую окно (пункт меню «⋯» уже скрыт — тогда на кнопку меню).
    const target = opener?.closest('.dropdown')?.querySelector('[data-bs-toggle="dropdown"]') ?? opener;
    target?.focus({ preventScroll: true });
    opener = null;
  });

  form.addEventListener('submit', (event) => {
    if (form.dataset.submitting === 'true') {
      event.preventDefault();
      return;
    }
    if (name.value.trim() === '') {
      event.preventDefault();
      setNameError('Введите название варианта');
      name.focus();
      return;
    }
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    submit.disabled = true;
    submit.textContent = 'Создаю…';
  });

  // Возврат «Назад» из кеша страницы: форма снова доступна.
  window.addEventListener('pageshow', () => {
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');
    submit.disabled = false;
    submit.textContent = 'Создать';
  });

  updateCounter();
  showPanel();
  if (modal.hasAttribute('data-budget-new-open')) {
    if (name.classList.contains('is-invalid')) name.setAttribute('aria-invalid', 'true');
    bootstrapModal(modal)?.show();
  }
}

function initArchive(root) {
  const toggle = root.querySelector('[data-budget-archive-toggle]');
  if (!toggle) return;
  const rows = document.getElementById(toggle.getAttribute('aria-controls'));
  toggle.addEventListener('click', () => {
    const expanded = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(expanded));
    rows.hidden = !expanded;
  });
}

function initYear(root) {
  const select = root.querySelector('[data-budget-year]');
  select?.addEventListener('change', () => select.form.submit());
}

export function initBudgetVersions(root = document.querySelector('[data-budget-page="versions"]')) {
  if (!root) return;
  initYear(root);
  initNewVersion(root);
  initArchive(root);
}
