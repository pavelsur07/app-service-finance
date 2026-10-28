/**
 * Страница «Операция ДДС» (templates/transaction/edit.html.twig), SPEC §2.2–2.3:
 * сумма и валюта счёта, счётчик описания, несохранённые правки, проверка перед
 * отправкой со сводкой, «Выйти без сохранения?». Серверная валидация остаётся
 * источником истины: здесь только подсказки до отправки.
 */
const AMOUNT_RE = /^\d+(\.\d+)?$/;

function parseAmount(raw) {
  const s = raw.replace(/[\s  ]/g, '').replace(',', '.');
  return AMOUNT_RE.test(s) ? s : null;
}

// Без разрядов: сервер (локаль en) пробелы в числе не принимает, запятую — принимает.
function formatAmount(raw) {
  const s = parseAmount(raw);
  if (s === null) return raw;
  const [whole, frac = ''] = s.split('.');
  if (frac.length > 2) return s.replace('.', ',');
  return `${whole.replace(/^0+(?=\d)/, '')},${frac.padEnd(2, '0')}`;
}

function fieldOf(control) {
  return control.closest('.dds-op__field');
}

function feedbackOf(control) {
  return fieldOf(control)?.querySelector('.invalid-feedback') ?? null;
}

function labelOf(control) {
  const field = fieldOf(control);
  const label = field?.querySelector('legend, label.dds-op__label');
  return label ? label.textContent.replace('*', '').trim() : '';
}

function setError(control, message) {
  const field = fieldOf(control);
  const targets = control.type === 'radio' ? field.querySelectorAll('input[type=radio]') : [control];
  targets.forEach((t) => {
    t.classList.toggle('is-invalid', !!message);
    if (message) t.setAttribute('aria-invalid', 'true');
    else t.removeAttribute('aria-invalid');
  });
  field?.querySelector('.dds-field')?.classList.toggle('is-invalid', !!message);
  const feedback = feedbackOf(control);
  if (feedback) feedback.textContent = message;
}

export function initDdsOperation(root = document.querySelector('[data-dds-op]')) {
  if (!root) return;
  const form = root.querySelector('[data-dds-op-form]');
  if (!form) return;

  const amount = form.querySelector('[data-dds-op-amount-input]');
  const amountBox = form.querySelector('[data-dds-op-amount]');
  const account = form.querySelector('[data-dds-op-account]');
  const description = form.querySelector('[data-dds-op-description]');
  const counter = root.querySelector('[data-dds-op-counter]');
  const summary = root.querySelector('[data-dds-op-summary]');
  const save = root.querySelector('[data-dds-op-save]');
  const status = root.querySelector('[data-dds-op-status]');
  const badge = root.querySelector('[data-dds-op-dirty-badge]');
  const leaveModal = document.querySelector('[data-dds-op-leave]');

  /* ---------- сумма и валюта ---------- */
  if (amount) {
    amount.value = formatAmount(amount.value);
    amount.addEventListener('blur', () => {
      amount.value = formatAmount(amount.value);
    });
  }

  if (account && amountBox) {
    const names = JSON.parse(amountBox.dataset.currencyNames || '{}');
    const symbols = JSON.parse(amountBox.dataset.currencySymbols || '{}');
    const symbolEl = amountBox.querySelector('[data-dds-op-currency-symbol]');
    const hint = root.querySelector('[data-dds-op-currency-hint]');
    account.addEventListener('change', () => {
      const code = account.selectedOptions[0]?.dataset.currency || '';
      if (!code) return;
      symbolEl.textContent = symbols[code] || code;
      hint.textContent = `Валюта берётся из счёта: ${names[code] || code} (${code})`;
    });
  }

  if (description && counter) {
    const sync = () => {
      counter.textContent = `${description.value.length} из 500`;
    };
    description.addEventListener('input', sync);
    sync();
  }

  if (root.hasAttribute('data-dds-op-deleted') || !save) return;

  /* ---------- несохранённые правки ---------- */
  const snapshot = () => {
    const data = new FormData(form);
    data.delete(`${form.name}[_token]`);
    if (amount) data.set(amount.name, parseAmount(amount.value) ?? amount.value);
    return JSON.stringify([...data.entries()]);
  };
  const initial = snapshot();
  // После ответа сервера с ошибками в форме уже чужие для БД значения — их можно сохранить сразу.
  const serverErrors = !!summary && !summary.hidden;
  let dirty = false;
  let leaving = false;

  const syncDirty = () => {
    dirty = serverErrors || snapshot() !== initial;
    root.toggleAttribute('data-dirty', dirty);
    if (badge) badge.hidden = !dirty;
    if (status) status.textContent = dirty ? 'Есть несохранённые изменения' : 'Все изменения сохранены';
    save.disabled = !dirty;
  };
  // Пикеры меняют скрытые select по-разному (не все шлют change) — пересчёт и после кликов.
  ['input', 'change'].forEach((type) => form.addEventListener(type, syncDirty));
  form.addEventListener('click', () => setTimeout(syncDirty));
  document.addEventListener('hidden.bs.modal', () => setTimeout(syncDirty));
  syncDirty();

  /* ---------- проверка перед отправкой ---------- */
  const validate = () => {
    const errors = [];
    form.querySelectorAll('[data-dds-op-required]').forEach((control) => {
      let message = '';
      if (control.type === 'radio') {
        if (!form.querySelector(`input[name="${CSS.escape(control.name)}"]:checked`)) message = control.dataset.ddsOpRequired;
      } else if (control === amount) {
        const value = parseAmount(amount.value);
        if (amount.value.trim() === '') message = 'Укажите сумму';
        else if (value === null) message = 'Введите число, например 1 250,00';
        else if (Number(value) <= 0) message = 'Сумма должна быть больше нуля';
      } else if (control.value.trim() === '') {
        message = control.dataset.ddsOpRequired;
      }
      setError(control, message);
      if (message) errors.push({ control, text: `${labelOf(control)}: ${message}` });
    });
    return errors;
  };

  const showSummary = (items) => {
    if (!summary) return;
    summary.querySelector('[data-dds-op-summary-title]').textContent = `Не удалось сохранить: ${items.length}. Исправьте отмеченные поля.`;
    const list = summary.querySelector('[data-dds-op-summary-list]');
    list.replaceChildren(...items.map((item) => {
      const li = document.createElement('li');
      li.textContent = item.text;
      return li;
    }));
    summary.hidden = items.length === 0;
  };

  const focusControl = (control) => {
    const picker = fieldOf(control)?.querySelector('.dds-field__main');
    (picker || control).focus();
    fieldOf(control)?.scrollIntoView({ block: 'center' });
  };

  form.addEventListener('submit', (event) => {
    const errors = validate();
    if (errors.length) {
      event.preventDefault();
      showSummary(errors);
      focusControl(errors[0].control);
      return;
    }
    if (amount) amount.value = formatAmount(amount.value).replace(/[\s  ]/g, '');
    leaving = true;
    save.disabled = true;
  });

  // Ответ сервера с ошибками: те же рамки и фокус на первое поле с ошибкой.
  if (serverErrors) {
    const invalid = [...form.querySelectorAll('.invalid-feedback')].filter((f) => f.textContent.trim() !== '');
    invalid.forEach((f) => {
      f.closest('.dds-op__field')?.querySelectorAll('input:not([type=hidden]), select, textarea').forEach((c) => c.classList.add('is-invalid'));
      f.closest('.dds-op__field')?.querySelector('.dds-field')?.classList.add('is-invalid');
    });
    const first = invalid[0]?.closest('.dds-op__field')?.querySelector('input:not([type=hidden]), select, textarea');
    if (first) focusControl(first);
    else summary.focus();
  }

  /* ---------- уход со страницы ---------- */
  window.addEventListener('beforeunload', (event) => {
    if (!dirty || leaving) return;
    event.preventDefault();
    event.returnValue = '';
  });

  const cancel = root.querySelector('[data-dds-op-cancel]');
  if (cancel && leaveModal) {
    let target = '';
    cancel.addEventListener('click', (event) => {
      if (!dirty) return;
      event.preventDefault();
      target = cancel.href;
      // Tabler кладёт Bootstrap в window.tabler.bootstrap; base.html.twig ставит алиас window.bootstrap.
      const Modal = (window.bootstrap || window.tabler?.bootstrap)?.Modal;
      if (Modal) {
        Modal.getOrCreateInstance(leaveModal).show();
      } else if (window.confirm('Выйти без сохранения? Внесённые изменения будут потеряны.')) {
        leaving = true;
        window.location.href = target;
      }
    });
    leaveModal.querySelector('[data-dds-op-leave-confirm]').addEventListener('click', () => {
      leaving = true;
      window.location.href = target;
    });
  }
}
