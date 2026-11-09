/**
 * «Товары и остатки» (templates/marketplace/inventory/index.html.twig): модал «Себестоимость» получает
 * action, CSRF-токен и название листинга с кнопки; модал импорта себестоимости переключает тип
 * идентификатора (SKU Ozon — только для Ozon), подпись колонки A и предупреждение по артикулу продавца.
 * Корень — [data-marketplace-inventory].
 */
const SUPPLIER_WARNING = {
  wildberries: 'Артикул продавца Wildberries берется из vendorCode карточки, регистр букв не учитывается. Одна цена будет применена ко всем размерам с этим артикулом. Артикулы, отличающиеся только регистром, будут пропущены с ошибкой — для них используйте импорт по баркоду.',
  ozon: 'Артикул продавца Ozon берется из offer_id. Перед загрузкой по артикулу продавца убедитесь, что выполнена синхронизация баркодов/артикулов Ozon.',
  other: 'Выберите маркетплейс, чтобы увидеть правила импорта по артикулу продавца.',
};

function initSetCost() {
  document.getElementById('modal-set-cost')?.addEventListener('show.bs.modal', (event) => {
    const btn = event.relatedTarget;
    document.getElementById('form-set-cost').action = btn.getAttribute('data-action');
    document.getElementById('set-cost-token').value = btn.getAttribute('data-token');
    document.getElementById('modal-set-cost-name').textContent = btn.getAttribute('data-listing-name');
  });
}

function initImportCost() {
  const modal = document.getElementById('modal-import-cost');
  if (!modal) return;
  const marketplaceSelect = modal.querySelector('select[name="marketplace"]');
  const identifierSelect = document.getElementById('import-cost-identifier-type');
  const formatALabel = document.getElementById('import-cost-format-a');
  const supplierWarning = document.getElementById('import-cost-supplier-warning');
  const supplierWarningText = document.getElementById('import-cost-supplier-warning-text');
  if (!marketplaceSelect || !identifierSelect || !formatALabel || !supplierWarning || !supplierWarningText) return;

  const updateState = () => {
    const isOzon = marketplaceSelect.value === 'ozon';
    const isWildberries = marketplaceSelect.value === 'wildberries';

    Array.from(identifierSelect.options).forEach((option) => {
      if (option.value === 'marketplace_sku') {
        option.disabled = !isOzon;
        option.hidden = !isOzon;
      }
    });
    if (!isOzon && identifierSelect.value === 'marketplace_sku') identifierSelect.value = 'barcode';

    if (identifierSelect.value === 'marketplace_sku') {
      formatALabel.textContent = 'Колонка A — SKU Ozon';
    } else if (identifierSelect.value === 'supplier_sku') {
      if (isWildberries) formatALabel.textContent = 'Колонка A — Артикул продавца Wildberries / vendorCode';
      else if (isOzon) formatALabel.textContent = 'Колонка A — Артикул продавца Ozon / offer_id';
      else formatALabel.textContent = 'Колонка A — Артикул продавца';
    } else {
      formatALabel.textContent = 'Колонка A — Баркод товара';
    }

    supplierWarning.classList.toggle('d-none', identifierSelect.value !== 'supplier_sku');
    supplierWarningText.textContent = SUPPLIER_WARNING[isWildberries ? 'wildberries' : (isOzon ? 'ozon' : 'other')];
  };

  marketplaceSelect.addEventListener('change', updateState);
  identifierSelect.addEventListener('change', updateState);
  modal.addEventListener('show.bs.modal', updateState);
  updateState();
}

export function initMarketplaceInventory() {
  if (!document.querySelector('[data-marketplace-inventory]')) return;
  initSetCost();
  initImportCost();
}
