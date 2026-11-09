/**
 * Платёжный календарь (templates/payment_calendar/index.html.twig): форма записи (новая / правка)
 * открывается модалом сразу после загрузки. Без Tabler модал показывается вручную, а кнопки
 * закрытия прячут его сами. Корень — #payment-plan-modal[data-payment-plan-autoopen].
 */
import { bootstrapModal } from '../bootstrap_modal.js';

const hideFallbackModal = (modalElement) => {
  const { activeElement } = document;
  if (activeElement && modalElement.contains(activeElement)) {
    if (typeof activeElement.blur === 'function') activeElement.blur();
    if (document.body && typeof document.body.focus === 'function') document.body.focus({ preventScroll: true });
  }
  modalElement.classList.remove('show');
  modalElement.style.display = 'none';
  modalElement.setAttribute('aria-hidden', 'true');
  modalElement.removeAttribute('aria-modal');
};

const setupFallbackDismiss = (modalElement) => {
  modalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => {
    if (button.dataset.dismissListenerAttached === 'true') return;
    button.addEventListener('click', (event) => {
      event.preventDefault();
      hideFallbackModal(modalElement);
    });
    button.dataset.dismissListenerAttached = 'true';
  });
};

export function initPaymentCalendar() {
  const modalElement = document.querySelector('#payment-plan-modal[data-payment-plan-autoopen]');
  if (!modalElement) return;
  const BootstrapModal = bootstrapModal();
  if (!BootstrapModal) {
    modalElement.classList.add('show');
    modalElement.style.display = 'block';
    modalElement.removeAttribute('aria-hidden');
    modalElement.setAttribute('aria-modal', 'true');
    modalElement.scrollTop = 0;
    setupFallbackDismiss(modalElement);
    return;
  }
  BootstrapModal.getOrCreateInstance(modalElement).show();
}
