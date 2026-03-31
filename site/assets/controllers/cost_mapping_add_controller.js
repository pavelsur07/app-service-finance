import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['category', 'costType', 'marketplace', 'submitButton'];
    static values = { addUrl: String };

    async add() {
        const categorySelect = this.categoryTarget;
        const costTypeSelect = this.costTypeTarget;
        const categoryId   = categorySelect.value;
        const categoryName = categorySelect.selectedOptions[0]?.dataset.name ?? '';
        const costType     = costTypeSelect.value;

        if (!categoryId || !costType) {
            alert('Выберите категорию затрат и статью юнит-экономики');
            return;
        }

        this.#setLoading(true);

        try {
            const res = await fetch(this.addUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    marketplace:         this.marketplaceTarget.value,
                    costCategoryId:      categoryId,
                    costCategoryName:    categoryName,
                    unitEconomyCostType: costType,
                }),
            });

            if (!res.ok) {
                const data = await res.json();
                this.#showError(data.message ?? 'Ошибка добавления');
                return;
            }

            window.location.reload();
        } catch {
            this.#showError('Ошибка сети');
        } finally {
            this.#setLoading(false);
        }
    }

    #setLoading(bool) {
        this.submitButtonTarget.disabled = bool;
    }

    #showError(msg) {
        alert(msg);
    }
}
