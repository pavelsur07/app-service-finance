import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['select'];
    static values = { remapUrl: String };

    async remap() {
        this.#setLoading(true);

        try {
            const res = await fetch(this.remapUrlValue, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ unitEconomyCostType: this.selectTarget.value }),
            });

            if (!res.ok) {
                this.#showError('Не удалось сохранить');
                return;
            }

            this.#updateBadge();
        } catch {
            this.#showError('Не удалось сохранить');
        } finally {
            this.#setLoading(false);
        }
    }

    #updateBadge() {
        // badges removed from UI
    }

    #setLoading(bool) {
        this.selectTarget.disabled = bool;
    }

    #showError(msg) {
        alert(msg);
    }
}
