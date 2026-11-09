/**
 * Разбивка операции по статьям (templates/transaction/splits.html.twig): итог и остаток пересчитываются
 * на ввод, строки добавляются из прототипа и удаляются (последняя остаётся), «Остаток в последнюю строку».
 * Корень — [data-splits-form].
 */
// Считаем в копейках: сложение долей в float даёт 0.1 + 0.2 !== 0.3 ровно там,
// где расхождение в копейку блокирует сохранение.
const kopecks = (value) => {
  const parsed = parseFloat(String(value || '').replace(',', '.').trim());
  return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
};

// DS-Money: разряды — неразрывный пробел, запятая, минус U+2212.
const format = (k) => {
  const abs = Math.abs(k);
  const whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  return `${k < 0 ? '−' : ''}${whole},${String(abs % 100).padStart(2, '0')}`;
};

export function initTransactionSplits() {
  const form = document.querySelector('[data-splits-form]');
  if (!form) return;

  const rows = form.querySelector('[data-splits-rows]');
  const totalEl = form.querySelector('[data-splits-total]');
  const restEl = form.querySelector('[data-splits-rest]');
  const target = Math.round(parseFloat(document.querySelector('[data-transaction-amount]').dataset.transactionAmount) * 100);
  const prototype = document.querySelector('[data-split-prototype]');

  const recalc = () => {
    let sum = 0;
    rows.querySelectorAll('.js-split-amount').forEach((input) => { sum += kopecks(input.value); });
    totalEl.textContent = format(sum);
    restEl.textContent = format(target - sum);
    restEl.classList.toggle('text-danger', target - sum !== 0);
  };

  rows.addEventListener('input', recalc);

  form.querySelector('[data-add-row]').addEventListener('click', () => {
    const index = parseInt(rows.dataset.nextIndex, 10);
    rows.dataset.nextIndex = String(index + 1);
    const holder = document.createElement('tbody');
    holder.innerHTML = prototype.innerHTML.replace(/__name__/g, String(index)).trim();
    rows.appendChild(holder.querySelector('tr'));
    recalc();
  });

  rows.addEventListener('click', (event) => {
    if (!event.target.matches('[data-remove-row]')) return;
    if (rows.querySelectorAll('[data-split-row]').length <= 1) return;
    event.target.closest('[data-split-row]').remove();
    recalc();
  });

  form.querySelector('[data-fill-rest]').addEventListener('click', () => {
    const inputs = rows.querySelectorAll('.js-split-amount');
    if (!inputs.length) return;
    let others = 0;
    inputs.forEach((input, index) => { if (index < inputs.length - 1) others += kopecks(input.value); });
    inputs[inputs.length - 1].value = ((target - others) / 100).toFixed(2);
    recalc();
  });

  recalc();
}
