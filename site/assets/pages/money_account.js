/**
 * Форма денежного счёта (templates/profile/money_account/new.html.twig): поле «Расчётный
 * счёт» только для типа bank, бейдж «Основной» следует за чекбоксом.
 */
export function initMoneyAccountForm() {
  const form = document.querySelector('[data-money-account-form]');
  if (!form || form.dataset.moneyAccountBound === '1') return;

  const typeInputs = form.querySelectorAll('[data-money-account-type]');
  const numberField = form.querySelector('[data-money-account-number-field]');
  const numberInput = form.querySelector('[data-money-account-number]');
  const defaultInput = form.querySelector('[data-money-account-default]');
  const defaultBadge = form.querySelector('[data-money-account-default-badge]');

  const syncType = () => {
    const selected = form.querySelector('[data-money-account-type]:checked');
    const isBank = !!selected && selected.value === 'bank';
    numberField?.classList.toggle('d-none', !isBank);
    if (numberInput) numberInput.disabled = !isBank;
  };
  const syncDefault = () => {
    if (defaultInput && defaultBadge) defaultBadge.classList.toggle('d-none', !defaultInput.checked);
  };

  typeInputs.forEach((input) => input.addEventListener('change', syncType));
  defaultInput?.addEventListener('change', syncDefault);
  form.dataset.moneyAccountBound = '1';
  syncType();
  syncDefault();
}
