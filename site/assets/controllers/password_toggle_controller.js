import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['input'];

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

    // aria-pressed — источник состояния: по нему CSS показывает нужную иконку
    // (login-pw-eye / login-pw-eye-off, см. pages/login.css).
    const btn = this.element.querySelector('.password-toggle');
    if (btn) {
      const label = this.shown ? 'Скрыть пароль' : 'Показать пароль';
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
      btn.setAttribute('aria-pressed', String(this.shown));
    }
  }
}
