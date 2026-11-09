/**
 * Сверка с Ozon (templates/marketplace/ozon_reconciliation/index.html.twig): пересчёт идёт на сервере синхронно —
 * на submit кнопка блокируется со спиннером, показывается баннер ожидания, повторная отправка отменяется.
 * Корень — форма [data-ozon-reconciliation-run].
 */
export function initOzonReconciliation() {
  const form = document.querySelector('[data-ozon-reconciliation-run]');
  if (!form) return;
  form.addEventListener('submit', (event) => {
    if (form.dataset.submitted === '1') {
      event.preventDefault();
      return;
    }
    form.dataset.submitted = '1';
    const button = form.querySelector('button[type="submit"]');
    if (button) {
      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>';
      button.append(form.dataset.busyText);
    }
    document.getElementById('ozon-reconciliation-busy')?.classList.remove('d-none');
  });
}
