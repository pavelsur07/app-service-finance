import { Controller } from '@hotwired/stimulus';

// Вкладка «Склады» МойСклад: подсветка строк, которые будут записаны, и счётчик на кнопке. Без JS форма работает так же.
export default class extends Controller {
  static targets = ['row', 'count', 'save'];

  refresh() {
    let changed = 0;
    for (const row of this.rowTargets) {
      const select = row.querySelector('select[data-was]');
      const dirty = select !== null && select.value !== select.dataset.was;
      row.classList.toggle('table-active', dirty);
      changed += dirty ? 1 : 0;
    }
    if (this.hasCountTarget) {
      this.countTarget.textContent = String(changed);
    }
    if (this.hasSaveTarget) {
      this.saveTarget.disabled = changed === 0;
    }
  }

  reset() {
    // reset применяется после события — пересчитать на следующем тике.
    setTimeout(() => this.refresh());
  }
}
