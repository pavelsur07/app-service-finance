/**
 * «Маркетплейсы» (templates/marketplace/index.html.twig): модал «Применить выручку» берёт адрес
 * формы и CSRF-токен из кнопки-триггера; модал «Обработать месяц» ([data-bulk-process-url])
 * запускает пакетную обработку через API и перезагружает страницу.
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';

const SUBMIT_LABEL = 'Запустить обработку';

function initApplyRealization() {
  const modal = document.querySelector('[data-apply-realization-modal]');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!(trigger instanceof HTMLElement) || !trigger.dataset.action || !trigger.dataset.token) {
      // Без адреса и токена форма ушла бы на текущую страницу — не открываем.
      event.preventDefault();
      return;
    }
    modal.querySelector('[data-apply-realization-form]').action = trigger.dataset.action;
    modal.querySelector('[data-apply-realization-token]').value = trigger.dataset.token;
  });
}

function initBulkProcess() {
  const modal = document.querySelector('[data-bulk-process-url]');
  const button = modal?.querySelector('[data-bulk-process-submit]');
  if (!(modal instanceof HTMLElement) || !button) return;
  const reset = () => {
    button.disabled = false;
    button.textContent = SUBMIT_LABEL;
  };
  modal.addEventListener('show.bs.modal', reset);

  button.addEventListener('click', async () => {
    const marketplace = modal.querySelector('[data-bulk-marketplace]')?.value ?? '';
    const monthValue = modal.querySelector('[data-bulk-month]')?.value ?? '';
    if (!marketplace || !monthValue) {
      window.alert('Выберите маркетплейс и месяц');
      return;
    }
    const [year, month] = monthValue.split('-').map((part) => parseInt(part, 10));
    button.disabled = true;
    const spinner = document.createElement('span');
    spinner.className = 'spinner-border spinner-border-sm me-2';
    spinner.setAttribute('aria-hidden', 'true');
    button.replaceChildren(spinner, 'Запуск...');

    const Modal = bootstrapModal();
    try {
      await apiFetch(modal.dataset.bulkProcessUrl, { method: 'POST', body: { marketplace, year, month } });
      if (Modal) {
        modal.addEventListener('hidden.bs.modal', () => window.location.reload(), { once: true });
        Modal.getOrCreateInstance(modal).hide();
      } else {
        window.location.reload();
      }
    } catch (error) {
      Modal?.getOrCreateInstance(modal).hide();
      window.alert(`Ошибка: ${error.message || 'Неизвестная ошибка'}`);
      reset();
    }
  });
}

export function initMarketplaceIndex() {
  initApplyRealization();
  initBulkProcess();
}
