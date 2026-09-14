import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['rows', 'row', 'prototype', 'totals'];
  static values = { index: Number, currency: String };

  connect() {
    this.digits = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: this.currencyValue }).resolvedOptions().maximumFractionDigits;
    this.update();
  }

  add() {
    const html = this.prototypeTarget.innerHTML.replaceAll('__name__', String(this.indexValue));
    this.indexValue += 1;
    this.rowsTarget.insertAdjacentHTML('beforeend', html);
    this.rowTargets.at(-1)?.querySelector('select')?.focus();
    this.update();
  }

  remove(event) {
    event.currentTarget.closest('[data-balance-lines-target="row"]')?.remove();
    this.update();
  }

  update() {
    let asset = 0n;
    let passive = 0n;
    let complete = true;
    for (const row of this.rowTargets) {
      const account = row.querySelector('select[name$="[accountId]"]');
      const direction = row.querySelector('select[name$="[direction]"]');
      const amount = row.querySelector('input[name$="[amount]"]');
      const raw = amount?.value.replaceAll(/\s/g, '').replace(',', '.') ?? '';
      const parts = raw.split('.');
      const side = account?.selectedOptions[0]?.dataset.side;
      if (!/^\d+(\.\d+)?$/.test(raw) || (parts[1]?.length ?? 0) > this.digits || !side) {
        complete = false;
        continue;
      }
      const minor = BigInt(parts[0] + (parts[1] ?? '').padEnd(this.digits, '0'));
      const change = direction?.value === 'decrease' ? -minor : minor;
      if (side === 'asset') asset += change;
      else passive += change;
    }
    this.totalsTarget.textContent = complete
      ? `Изменение актива: ${this.format(asset)}; пассива: ${this.format(passive)}; разница: ${this.format(asset - passive)}. Итог проверяется при проведении.`
      : 'Заполните строки для предварительного расчета. Итог проверяется при проведении.';
  }

  format(value) {
    const sign = value < 0n ? '−' : '';
    const digits = (value < 0n ? -value : value).toString().padStart(this.digits + 1, '0');
    const decimal = this.digits === 0 ? digits : `${digits.slice(0, -this.digits)},${digits.slice(-this.digits)}`;
    return `${sign}${decimal} ${this.currencyValue}`;
  }
}
