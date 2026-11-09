/**
 * Карточка товара (templates/catalog/product/show.html.twig): кнопки [data-add-price-open] открывают модал
 * «Добавить цену»; без Tabler модал показывается вручную.
 */
import { bootstrapModal } from '../bootstrap_modal.js';

export function initProductShow() {
  document.querySelectorAll('[data-add-price-open]').forEach((button) => {
    button.addEventListener('click', () => {
      const modalEl = document.getElementById('modal-add-price');
      if (!modalEl) return;
      const Modal = bootstrapModal();
      if (Modal) {
        Modal.getOrCreateInstance(modalEl).show();
      } else {
        modalEl.style.display = 'block';
        modalEl.classList.add('show');
        document.body.classList.add('modal-open');
      }
    });
  });
}
