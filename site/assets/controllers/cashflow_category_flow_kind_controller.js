import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['parent', 'flowKind'];

  connect() {
    this.toggle();
  }

  toggle() {
    if (!this.hasParentTarget || !this.hasFlowKindTarget) {
      return;
    }

    const inheritsFromParent = '' !== this.parentTarget.value;
    this.flowKindTarget.disabled = inheritsFromParent;
    this.flowKindTarget.setAttribute('aria-disabled', String(inheritsFromParent));
  }
}
