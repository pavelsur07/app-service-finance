/**
 * Поля налогового режима (templates/company/_tax_profile_fields.html.twig): варианты НДС
 * ограничены матрицей data-matrix {режим: [коды НДС]} по выбранному режиму; сочетание
 * проверяет и сервер. Контейнер — [data-tax-profile-fields].
 */
export function initTaxProfileFields(root = document) {
  root.querySelectorAll('[data-tax-profile-fields]').forEach((box) => {
    if (box.dataset.taxProfileBound === '1') return;
    const matrix = JSON.parse(box.dataset.matrix || '{}');
    const regime = box.querySelector('[data-tax-regime]');
    const vat = box.querySelector('[data-vat-mode]');
    if (!regime || !vat) return;
    const sync = () => {
      const allowed = matrix[regime.value] ?? null;
      for (const option of vat.options) {
        if (option.value === '') continue;
        const ok = allowed === null || allowed.includes(option.value);
        option.hidden = !ok;
        option.disabled = !ok;
      }
      if (allowed === null) {
        vat.value = '';
      } else if (!allowed.includes(vat.value)) {
        vat.value = allowed[0];
      }
    };
    regime.addEventListener('change', sync);
    sync();
    box.dataset.taxProfileBound = '1';
  });
}
