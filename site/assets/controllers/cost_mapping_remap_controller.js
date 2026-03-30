import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['select', 'badge'];
    static values = { remapUrl: String, resetUrl: String };

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

            this.#updateBadge(false);
        } catch {
            this.#showError('Не удалось сохранить');
        } finally {
            this.#setLoading(false);
        }
    }

    async reset() {
        if (!confirm('Сбросить к системному значению?')) return;

        this.#setLoading(true);

        try {
            const res = await fetch(this.resetUrlValue, {
                method: 'PATCH',
                credentials: 'same-origin',
            });

            if (!res.ok) {
                this.#showError('Не удалось сбросить');
                return;
            }

            const data = await res.json();
            this.selectTarget.value = data.unit_economy_cost_type;
            this.#updateBadge(true);
        } catch {
            this.#showError('Не удалось сбросить');
        } finally {
            this.#setLoading(false);
        }
    }

    #updateBadge(isSystem) {
        this.badgeTarget.textContent = isSystem ? 'Системный' : 'Изменён';
        this.badgeTarget.className = isSystem
            ? 'badge bg-secondary-lt'
            : 'badge bg-warning-lt';
    }

    #setLoading(bool) {
        this.selectTarget.disabled = bool;
    }

    #showError(msg) {
        alert(msg);
    }
}
