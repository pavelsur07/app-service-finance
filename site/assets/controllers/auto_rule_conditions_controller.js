import { Controller } from '@hotwired/stimulus';

const OPERATORS = {
  COUNTERPARTY: ['EQUAL'],
  COUNTERPARTY_NAME: ['CONTAINS'],
  INN: ['CONTAINS'],
  DATE: ['EQUAL', 'BETWEEN'],
  AMOUNT: ['EQUAL', 'GREATER_THAN', 'LESS_THAN', 'BETWEEN'],
  DESCRIPTION: ['CONTAINS'],
};

export default class extends Controller {
  static targets = ['collection', 'status'];
  static values = { index: Number, prototype: String };

  connect() {
    this.collectionTarget.querySelectorAll('[data-auto-rule-condition]').forEach((item) => this.initialize(item));
    this.renumber();
  }

  add() {
    const item = document.createElement('div');
    const form = this.prototypeValue.replace(/__name__/g, this.indexValue);

    item.className = 'card mb-2';
    item.dataset.autoRuleCondition = '';
    item.innerHTML = `<div class="card-body">
      <h3 data-condition-title></h3>
      ${form}
      <button type="button" class="btn btn-sm btn-danger" data-action="auto-rule-conditions#remove" data-remove-condition>Удалить</button>
    </div>`;

    this.indexValue += 1;
    this.collectionTarget.appendChild(item);
    this.initialize(item);
    this.renumber();
    item.querySelector('[id$="_field"]')?.focus();
    this.statusTarget.textContent = 'Условие добавлено.';
  }

  remove(event) {
    event.currentTarget.closest('[data-auto-rule-condition]')?.remove();
    this.renumber();
    this.statusTarget.textContent = 'Условие удалено.';
  }

  initialize(item) {
    const field = item.querySelector('[id$="_field"]');
    const operator = item.querySelector('[id$="_operator"]');
    const value = item.querySelector('.condition-value-row input');

    if (!field || !operator) {
      return;
    }

    const update = () => this.updateCondition(item);
    field.addEventListener('change', update);
    operator.addEventListener('change', update);
    value?.addEventListener('input', () => {
      if ('INN' === field.value) {
        value.value = value.value.replace(/\D/g, '');
      }
    });
    update();
  }

  updateCondition(item) {
    const field = item.querySelector('[id$="_field"]');
    const operator = item.querySelector('[id$="_operator"]');
    const operatorRow = item.querySelector('.condition-operator-row');
    const counterpartyRow = item.querySelector('.condition-counterparty-row');
    const valueRow = item.querySelector('.condition-value-row');
    const valueToRow = item.querySelector('.condition-value-to-row');
    const valueInput = valueRow?.querySelector('input');
    const valueToInput = valueToRow?.querySelector('input');
    const allowed = OPERATORS[field?.value] ?? [];

    if (!field || !operator) {
      return;
    }

    Array.from(operator.options).forEach((option) => {
      const enabled = allowed.includes(option.value);
      option.disabled = !enabled;
      option.hidden = !enabled;
    });
    if (!allowed.includes(operator.value)) {
      operator.value = allowed[0] ?? '';
    }

    const isCounterparty = 'COUNTERPARTY' === field.value;
    const usesRange = ['DATE', 'AMOUNT'].includes(field.value) && 'BETWEEN' === operator.value;

    operatorRow.hidden = 1 === allowed.length;
    this.toggleRow(counterpartyRow, isCounterparty, !isCounterparty);
    this.toggleRow(valueRow, !isCounterparty, isCounterparty);
    this.toggleRow(valueToRow, usesRange, !usesRange);

    if (valueInput) {
      valueInput.type = 'DATE' === field.value ? 'date' : 'text';
      if ('INN' === field.value) {
        valueInput.inputMode = 'numeric';
      } else if ('AMOUNT' === field.value) {
        valueInput.inputMode = 'decimal';
      } else {
        valueInput.removeAttribute('inputmode');
      }
    }
    if (valueToInput) {
      valueToInput.type = 'DATE' === field.value ? 'date' : 'text';
      if ('AMOUNT' === field.value) {
        valueToInput.inputMode = 'decimal';
      } else {
        valueToInput.removeAttribute('inputmode');
      }
    }
  }

  toggleRow(row, visible, clear) {
    if (!row) {
      return;
    }

    row.hidden = !visible;
    row.querySelectorAll('input, select, textarea').forEach((control) => {
      control.disabled = !visible;
      if (clear && !visible) {
        control.value = '';
      }
    });
  }

  renumber() {
    this.collectionTarget.querySelectorAll('[data-auto-rule-condition]').forEach((item, index) => {
      const number = index + 1;
      const title = item.querySelector('[data-condition-title]');
      const remove = item.querySelector('[data-remove-condition]');
      if (title) {
        title.textContent = `Условие ${number}`;
      }
      if (remove) {
        remove.setAttribute('aria-label', `Удалить условие ${number}`);
      }
    });
  }
}
