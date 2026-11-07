/**
 * «Настройки» аналитики маркетплейсов (templates/marketplace_analytics/cost_mappings/index.html.twig):
 * смена маркетплейса — переход по data-index-url; добавление маппинга — POST на data-add-url;
 * удаление — DELETE по адресу триггера после диалога _confirm_modal (событие confirm:submit).
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';

export function initCostMappings() {
  const root = document.querySelector('[data-cost-mappings]');
  if (!(root instanceof HTMLElement)) return;
  const marketplace = root.querySelector('[data-cost-mapping-marketplace]');

  marketplace?.addEventListener('change', () => {
    window.location.assign(`${root.dataset.indexUrl}?marketplace=${encodeURIComponent(marketplace.value)}`);
  });

  root.querySelector('[data-cost-mapping-add]')?.addEventListener('click', async (event) => {
    const button = event.currentTarget;
    const costType = root.querySelector('[data-cost-mapping-type]')?.value ?? '';
    const category = root.querySelector('[data-cost-mapping-category]');
    if (!costType || !category?.value) {
      window.alert('Выберите статью и категорию затрат');
      return;
    }
    button.disabled = true;
    try {
      await apiFetch(root.dataset.addUrl, {
        method: 'POST',
        body: {
          marketplace: marketplace?.value ?? '',
          unitEconomyCostType: costType,
          costCategoryId: category.value,
          costCategoryName: category.selectedOptions[0]?.dataset.name ?? '',
        },
      });
      window.location.reload();
    } catch (error) {
      window.alert(error.message || 'Ошибка добавления');
      button.disabled = false;
    }
  });

  // Диалог подтверждения открыт кнопкой строки; удаление выполняем сами, форму диалога не отправляем.
  root.addEventListener('confirm:submit', async (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-cost-mapping-delete]') : null;
    if (!trigger) return;
    event.preventDefault();
    bootstrapModal()?.getOrCreateInstance(event.detail.modal).hide();
    trigger.disabled = true;
    try {
      await apiFetch(trigger.dataset.confirmAction, { method: 'DELETE', csrfToken: trigger.dataset.confirmToken });
      trigger.closest('tr')?.remove();
    } catch (error) {
      window.alert(error.message || 'Не удалось удалить маппинг');
      trigger.disabled = false;
    }
  });
}
