/**
 * «Интеграции» (templates/integrations/marketplace.html.twig): модал настройки показывает название сервиса
 * с нажатой кнопки (data-service). Корень — #modal-setup[data-integration-setup].
 */
export function initIntegrationsMarketplace() {
  document.querySelector('[data-integration-setup]')?.addEventListener('show.bs.modal', (event) => {
    document.getElementById('target-service').textContent = event.relatedTarget.getAttribute('data-service');
  });
}
