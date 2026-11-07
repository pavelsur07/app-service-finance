// Presentation only: reuse Bootstrap dropdowns and the existing entity pickers.
export function initSettlementUi() {
  const root = document.querySelector('[data-settlement-ui]');
  if (!(root instanceof HTMLElement) || !window.bootstrap?.Dropdown) return;
  root.dataset.settlementEnhanced = '';
  root.querySelectorAll('[data-settlement-period]').forEach((dropdown) => {
    dropdown.addEventListener('shown.bs.dropdown', () => {
      dropdown.querySelector('input[type="date"]')?.focus();
    });
  });
}
