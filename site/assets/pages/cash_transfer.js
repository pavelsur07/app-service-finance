/**
 * «Новый перевод» (templates/cash/transfer/new.html.twig): подпись валюты счёта рядом
 * с полем суммы — из data-currency выбранного option; data-currency-select="X" ↔
 * data-currency-output="X".
 */
export function initCashTransfer() {
  document.querySelectorAll('[data-currency-select]').forEach((select) => {
    const output = document.querySelector(`[data-currency-output="${select.dataset.currencySelect}"]`);
    if (!output) return;
    const update = () => { output.value = select.selectedOptions[0]?.dataset.currency || '—'; };
    select.addEventListener('change', update);
    update();
  });
}
