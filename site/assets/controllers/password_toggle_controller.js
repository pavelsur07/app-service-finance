import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['input', 'iconShow', 'iconHide'];

  connect() {
    this.shown = false;
    this.update();
  }

  toggle() {
    this.shown = !this.shown;
    this.update();
  }

  update() {
    this.inputTarget.type = this.shown ? 'text' : 'password';

    if (this.hasIconShowTarget && this.hasIconHideTarget) {
      this.iconShowTarget.hidden = this.shown;
      this.iconHideTarget.hidden = !this.shown;
    }

    const btn = this.element.querySelector('.password-toggle');
    if (btn) {
      const label = this.shown ? 'Скрыть пароль' : 'Показать пароль';
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      btn.setAttribute('aria-pressed', String(this.shown));
    }
  }
}
