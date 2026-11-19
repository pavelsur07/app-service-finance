import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['confirm'];

  requirePreview() {
    if (this.hasConfirmTarget) {
      this.confirmTarget.disabled = true;
    }
  }

  packDecisions({ formData }) {
    const decisions = {};
    const fields = [];
    for (const [name, value] of formData) {
      const match = /^decisions\[([0-9a-f-]+)\]\[(mode|code)\]$/.exec(name);
      if (!match) {
        continue;
      }

      const [, id, field] = match;
      decisions[id] ??= {};
      decisions[id][field] = value;
      fields.push(name);
    }

    for (const name of fields) {
      formData.delete(name);
    }
    const selected = Object.entries(decisions).filter(([, decision]) => decision.mode !== 'skip');
    formData.set('decisionsJson', JSON.stringify(Object.fromEntries(selected)));
  }
}
