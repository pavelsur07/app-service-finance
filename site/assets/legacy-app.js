import './styles/legacy-app.css';
import './styles/components/dds-pickers.css';
import './styles/pages/dds-operation.css';
import './styles/pages/budget.css';
import './styles/pages/settlement.css';
import './bootstrap.js';
import './project_direction_picker.js';
import './counterparty_picker.js';
import './picker_pill.js';
import './confirm_modal.js';
import './autosubmit.js';
import './busy_submit.js';
import { initDdsTreePickers, observeDdsTreePickers } from './dds_tree_picker.js';
import { initDdsOperation } from './pages/dds_operation.js';
import { initBudgetVersions } from './pages/budget_versions.js';
import { initBudgetVersion } from './pages/budget_version.js';
import { initSettlementUi } from './pages/settlement.js';
import { initBudgetPlanFact } from './pages/budget_plan_fact.js';
import { initCashTransfer } from './pages/cash_transfer.js';
import { initMoneyAccountForm } from './pages/money_account.js';
import { initPlCategoryEdit } from './pages/pl_category_edit.js';
import { initProductImportStatus } from './pages/product_import_status.js';
import { initReportFilters } from './pages/report_filters.js';
import { initTaxProfileFields } from './tax_profile_fields.js';
import { initMonthClose } from './pages/month_close.js';
import { initMarketplaceIndex } from './pages/marketplace_index.js';
import { initCostMappings } from './pages/cost_mappings.js';
import { initCostPlMapping } from './pages/cost_pl_mapping.js';
import { initDefaultSaleMapping } from './pages/default_sale_mapping.js';
import { initMarketplaceAds } from './pages/marketplace_ads.js';
import { initListingsIndex } from './pages/listings_index.js';
import { initListingTags } from './pages/listing_tags.js';
import { initCashTransactions } from './pages/cash_transactions.js';
import { initPnlDocument } from './pages/pnl_document.js';

// Period presets (partials/_period_presets.html.twig): dates come from the server.
document.addEventListener('click', (event) => {
  const button = event.target instanceof Element ? event.target.closest('[data-period-from]') : null;
  const group = button?.closest('[data-period-presets]');
  if (!button || !(group instanceof HTMLElement)) return;
  const from = document.querySelector(group.dataset.periodFromInput ?? '');
  const to = document.querySelector(group.dataset.periodToInput ?? '');
  if (!(from instanceof HTMLInputElement) || !(to instanceof HTMLInputElement)) return;
  from.value = button.dataset.periodFrom ?? '';
  to.value = button.dataset.periodTo ?? '';
});

// Виджеты «Статья ДДС» / «Категория ОПиУ» (partials/_dds_tree_picker.html.twig) поверх
// select формы, в том числе в строках, добавленных позже (коллекция операций документа);
// страница «Операция ДДС» — после виджетов (её снимок формы видит их значения).
// Flash-тосты (partials/_flash.html.twig): Bootstrap Toast из Tabler (window.bootstrap задаёт _layout/legacy).
// Без Tabler (CDN не ответил) тост просто остаётся видимым: сообщение важнее автоскрытия.
const showFlashToasts = () => {
  const Toast = (window.bootstrap || window.tabler?.bootstrap)?.Toast;
  document.querySelectorAll('.toast[data-bs-autohide]').forEach((toastEl) => {
    if (Toast) Toast.getOrCreateInstance(toastEl).show();
    else toastEl.classList.add('show');
  });
};

const initDds = () => {
  initDdsTreePickers();
  observeDdsTreePickers();
  initDdsOperation();
  initBudgetVersions();
  initBudgetVersion();
  initBudgetPlanFact();
  initSettlementUi();
  initCashTransfer();
  initMoneyAccountForm();
  initPlCategoryEdit();
  initProductImportStatus();
  initReportFilters();
  initTaxProfileFields();
  initMonthClose();
  initMarketplaceIndex();
  initCostMappings();
  initCostPlMapping();
  initDefaultSaleMapping();
  initMarketplaceAds();
  initListingsIndex();
  initListingTags();
  initCashTransactions();
  initPnlDocument();
  showFlashToasts();
};
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initDds);
} else {
  initDds();
}
