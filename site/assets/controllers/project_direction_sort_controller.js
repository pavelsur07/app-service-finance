import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['parent', 'sort'];
  static values = { nextByParent: Object };

  refresh() {
    if (!this.hasParentTarget || !this.hasSortTarget) {
      return;
    }

    const parentId = this.parentTarget.value;

    if (Object.hasOwn(this.nextByParentValue, parentId)) {
      this.sortTarget.value = String(this.nextByParentValue[parentId]);
    }
  }
}
