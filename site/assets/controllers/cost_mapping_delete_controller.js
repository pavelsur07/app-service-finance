import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { url: String };

    async delete() {
        if (!confirm('Удалить маппинг?')) return;

        try {
            const res = await fetch(this.urlValue, {
                method: 'DELETE',
                credentials: 'same-origin',
            });

            if (res.status === 204) {
                this.element.closest('tr').remove();
                return;
            }

            alert('Не удалось удалить маппинг');
        } catch {
            alert('Ошибка сети');
        }
    }
}
