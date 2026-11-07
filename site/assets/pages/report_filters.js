/**
 * Панель фильтров отчётов ОПиУ и ДДС (form[data-report-filters] в finance/report/preview и
 * report/cashflow): пресеты периода, месяцы → точные даты, множественные фильтры,
 * автоотправка, один открытый dropdown.
 */
function initForm(form) {
  const exactFrom = form.querySelector('[data-pl-exact-from]');
  const exactTo = form.querySelector('[data-pl-exact-to]');
  const monthFrom = form.querySelector('[data-pl-month-from]');
  const monthTo = form.querySelector('[data-pl-month-to]');

  const submitFilters = () => form.requestSubmit();

  const monthEnd = (month) => {
    const [year, monthNumber] = month.split('-').map(Number);
    const day = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate();
    return `${month}-${String(day).padStart(2, '0')}`;
  };
  const validMonth = (month) => /^\d{4}-(0[1-9]|1[0-2])$/.test(month);

  const activatePluralFilter = (group) => {
    const legacyFilter = form.querySelector(`[data-pl-legacy-filter="${group}"]`);
    if (!legacyFilter) return;
    legacyFilter.remove();
    const marker = form.querySelector(`[data-pl-filter-marker="${group}"]`);
    if (marker) marker.disabled = false;
    form.querySelectorAll(`[data-pl-filter-input="${group}"][data-pl-filter-name]`).forEach((input) => {
      input.name = input.getAttribute('data-pl-filter-name');
      input.removeAttribute('data-pl-filter-name');
    });
  };

  form.querySelectorAll('[data-pl-period-from][data-pl-period-to]').forEach((button) => {
    button.addEventListener('click', () => {
      exactFrom.value = button.getAttribute('data-pl-period-from');
      exactTo.value = button.getAttribute('data-pl-period-to');
      submitFilters();
    });
  });

  monthFrom?.addEventListener('change', () => {
    if (!validMonth(monthFrom.value)) return;
    exactFrom.value = `${monthFrom.value}-01`;
    if (exactFrom.value > exactTo.value) {
      monthTo.value = monthFrom.value;
      exactTo.value = monthEnd(monthFrom.value);
    }
    submitFilters();
  });

  monthTo?.addEventListener('change', () => {
    if (!validMonth(monthTo.value)) return;
    exactTo.value = monthEnd(monthTo.value);
    if (exactTo.value < exactFrom.value) {
      monthFrom.value = monthTo.value;
      exactFrom.value = `${monthTo.value}-01`;
    }
    submitFilters();
  });

  form.querySelectorAll('[data-pl-set]').forEach((button) => {
    button.addEventListener('click', () => {
      const input = form.querySelector(`[data-pl-${button.getAttribute('data-pl-set')}]`);
      if (!input) return;
      input.value = button.getAttribute('data-pl-value');
      submitFilters();
    });
  });

  form.querySelectorAll('[data-pl-filter-input]').forEach((input) => {
    input.addEventListener('change', () => {
      activatePluralFilter(input.getAttribute('data-pl-filter-input'));
      submitFilters();
    });
  });

  form.querySelectorAll('[data-pl-auto-submit]').forEach((input) => {
    input.addEventListener('change', submitFilters);
  });

  form.querySelectorAll('[data-pl-select-all]').forEach((button) => {
    button.addEventListener('click', () => {
      const group = button.getAttribute('data-pl-select-all');
      activatePluralFilter(group);
      form.querySelectorAll(`[data-pl-filter-input="${group}"]`).forEach((input) => { input.checked = true; });
      submitFilters();
    });
  });

  const dropdowns = Array.from(form.querySelectorAll('details.pl-preview-dropdown'));
  dropdowns.forEach((dropdown) => {
    dropdown.addEventListener('toggle', () => {
      if (!dropdown.open) return;
      dropdowns.forEach((other) => { if (other !== dropdown) other.open = false; });
    });
  });
  document.addEventListener('click', (event) => {
    if (event.target.closest('.pl-preview-dropdown')) return;
    dropdowns.forEach((dropdown) => { dropdown.open = false; });
  });
}

export function initReportFilters() {
  document.querySelectorAll('form[data-report-filters]').forEach((form) => {
    if (form.dataset.reportFiltersBound === '1') return;
    form.dataset.reportFiltersBound = '1';
    initForm(form);
  });
}
