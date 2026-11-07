/**
 * Реестр листингов (templates/marketplace/listings/index.html.twig): фильтры «применяются сразу»,
 * привязка листинга к товару (модал со списком товаров из API), массовое назначение тега
 * выбранным строкам или всем по фильтру (через _confirm_modal) и снятие тега.
 * Адреса API — на корне [data-listings-index]; данные API — только textContent.
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';
import { el } from '../dom.js';
import { withId } from '../url_template.js';

const FLASH_KEY = 'vf.listings.tagResult';
const formatCount = (n) => Number(n).toLocaleString('ru-RU').replace(/\s/g, ' ');
const isOne = (n) => n % 10 === 1 && n % 100 !== 11;
const listingsDative = (n) => (isOne(n) ? 'листингу' : 'листингам');
const listingsGenitive = (n) => (isOne(n) ? 'листинга' : 'листингов');

const resultText = (data) => {
  const already = data.matched - data.assigned;
  if (data.assigned === 0 && already > 0) {
    return `Тег «${data.tagName}» уже был у всех ${formatCount(already)} ${listingsGenitive(already)}.`;
  }
  let text = `Тег «${data.tagName}»: назначен ${formatCount(data.assigned)} ${listingsDative(data.assigned)}`;
  if (already > 0) text += `, у ${formatCount(already)} уже был`;
  return `${text}.`;
};

// Итог назначения переживает перезагрузку через sessionStorage; без хранилища
// (приватный режим) итог показывается alert'ом до перезагрузки.
const rememberFlash = (text) => {
  try {
    sessionStorage.setItem(FLASH_KEY, text);
  } catch {
    window.alert(text);
  }
};
const takeRememberedFlash = () => {
  try {
    const text = sessionStorage.getItem(FLASH_KEY);
    sessionStorage.removeItem(FLASH_KEY);
    return text;
  } catch {
    return null;
  }
};

// После назначения или снятия тега выборка по тегу может сжаться, и текущая страница окажется
// за её концом (OutOfRangeCurrentPage). Фильтры сохраняем, страницу — нет.
const reloadFromFirstPage = () => {
  const url = new URL(window.location.href);
  url.searchParams.delete('page');
  window.location.assign(url.toString());
};

const initFilterForm = (root) => {
  const form = root.querySelector('[data-listings-filter]');
  if (!form) return;
  const fields = () => form.querySelectorAll('input[name], select[name]');
  form.addEventListener('change', (event) => {
    if (event.target.matches('[data-autosubmit]')) form.requestSubmit();
  });
  // Пустые поля не попадают в адрес: ?q=&tag= ничего не значат.
  form.addEventListener('submit', () => fields().forEach((field) => { field.disabled = field.value === ''; }));
  // Возврат «Назад» берёт страницу из bfcache вместе с отключёнными полями.
  window.addEventListener('pageshow', () => fields().forEach((field) => { field.disabled = false; }));
};

const initMapModal = (root) => {
  const modal = document.querySelector('[data-map-listing-modal]');
  if (!modal) return;
  const sku = modal.querySelector('[data-map-listing-sku]');
  const select = modal.querySelector('[data-map-product]');
  const error = modal.querySelector('[data-map-error]');
  const submit = modal.querySelector('[data-map-submit]');
  let listingId = null;

  const showError = (text) => {
    error.textContent = text;
    error.classList.remove('d-none');
  };
  const restore = () => {
    submit.disabled = false;
    submit.textContent = 'Привязать';
  };
  const option = (value, label) => {
    const node = el('option', '', [label]);
    node.value = value; // без value option отдаёт текст — пустой плейсхолдер прошёл бы проверку
    return node;
  };
  const setOptions = (placeholder, products = []) => {
    select.replaceChildren(option('', placeholder), ...products.map((product) => option(product.id, `${product.sku} — ${product.name}`)));
  };
  const loadProducts = async () => {
    setOptions('Загрузка...');
    select.disabled = true;
    try {
      setOptions('— выберите товар —', await apiFetch(root.dataset.productsUrl));
    } catch {
      setOptions('Ошибка загрузки');
    } finally {
      select.disabled = false;
    }
  };

  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-map-listing]');
    if (!button) return;
    listingId = button.dataset.listingId;
    sku.textContent = button.dataset.listingSku;
    error.classList.add('d-none');
    loadProducts();
    bootstrapModal()?.getOrCreateInstance(modal).show();
  });
  modal.addEventListener('hidden.bs.modal', restore);
  submit.addEventListener('click', async () => {
    error.classList.add('d-none');
    if (!select.value) {
      showError('Выберите товар');
      return;
    }
    submit.disabled = true;
    submit.replaceChildren(el('span', 'spinner-border spinner-border-sm me-1'), 'Сохранение...');
    try {
      await apiFetch(withId(root.dataset.mapUrl, listingId), { method: 'POST', body: { productId: select.value } });
      window.location.reload();
    } catch (e) {
      showError(e.status ? e.message : 'Ошибка сети');
      restore();
    }
  });
};

const initTags = (root) => {
  const flashBox = root.querySelector('[data-listings-flash]');
  const errorBox = root.querySelector('[data-listings-error]');
  const showMessage = (box, text) => {
    box.querySelector('[data-message]').textContent = text;
    box.classList.remove('d-none');
  };
  const flash = takeRememberedFlash();
  if (flash && flashBox) showMessage(flashBox, flash);

  const panel = root.querySelector('[data-bulk-tag-panel]');
  if (!panel) return;

  const assignUrl = root.dataset.assignUrl;
  const allTags = JSON.parse(panel.dataset.allTags || '[]');
  const appliedFilter = JSON.parse(panel.dataset.appliedFilter || '{}');
  const nbResults = Number(panel.dataset.nbResults || 0);
  const rowBoxes = Array.from(root.querySelectorAll('.row-select'));
  const selectAll = root.querySelector('[data-select-all]');
  const count = panel.querySelector('[data-bulk-count]');
  const scopeOffer = panel.querySelector('[data-bulk-scope-offer]');
  const scopeFilter = panel.querySelector('[data-bulk-scope-filter]');
  const tagInputWrap = panel.querySelector('[data-bulk-tag-input]');
  const tagInput = panel.querySelector('[data-bulk-tag-name]');
  const suggestBox = panel.querySelector('[data-bulk-tag-suggest]');
  const confirmTrigger = panel.querySelector('[data-bulk-assign-trigger]');
  const confirmModal = document.querySelector(confirmTrigger.dataset.bsTarget);

  // page — отмеченные строки страницы; filter — все листинги по применённому фильтру.
  let scope = 'page';
  let pendingTagRef = null;

  const selectedIds = () => rowBoxes.filter((cb) => cb.checked).map((cb) => cb.value);
  const allOnPageChecked = () => rowBoxes.length > 0 && rowBoxes.every((cb) => cb.checked);
  const hideSuggest = () => suggestBox.classList.add('d-none');

  const refreshPanel = () => {
    if (scope === 'filter' && !allOnPageChecked()) scope = 'page';
    const selected = scope === 'filter' ? nbResults : selectedIds().length;
    count.textContent = formatCount(selected);
    scopeOffer.classList.toggle('d-none', !(scope === 'page' && allOnPageChecked() && nbResults > rowBoxes.length));
    scopeFilter.classList.toggle('d-none', scope !== 'filter');
    if (selectAll) selectAll.checked = allOnPageChecked();
    panel.classList.toggle('d-none', selected === 0);
    if (selected === 0) hideSuggest();
  };

  // Ответ API или null; ошибка — видимым текстом над таблицей.
  const post = async (url, body) => {
    errorBox.classList.add('d-none');
    try {
      return await apiFetch(url, { method: 'POST', body });
    } catch (e) {
      showMessage(errorBox, e.status ? e.message : 'Ошибка сети. Проверьте соединение и повторите.');
      return null;
    }
  };
  const finishAssign = (data) => {
    rememberFlash(resultText(data));
    reloadFromFirstPage();
  };

  // Назначение всем по фильтру — через диалог подтверждения; JSON-запрос шлёт модуль по confirm:submit,
  // форма диалога не отправляется.
  const openFilterConfirm = (tagRef, tagLabel) => {
    pendingTagRef = tagRef;
    Object.assign(confirmTrigger.dataset, {
      confirmAction: assignUrl,
      confirmTitle: `Присвоить тег «${tagLabel}» ${formatCount(nbResults)} ${listingsDative(nbResults)}?`,
      confirmBody: `Выборка: ${panel.dataset.filterSummary}. Тег получат все листинги по этому фильтру, а не только строки текущей страницы.`,
    });
    bootstrapModal()?.getOrCreateInstance(confirmModal).show(confirmTrigger);
  };
  confirmTrigger.addEventListener('confirm:submit', async (event) => {
    event.preventDefault();
    const submit = confirmModal.querySelector('[data-confirm-submit]');
    submit.disabled = true;
    const data = await post(assignUrl, { filter: appliedFilter, ...pendingTagRef });
    if (data) {
      finishAssign(data);
      return;
    }
    bootstrapModal()?.getOrCreateInstance(confirmModal).hide();
    submit.disabled = false;
  });

  const assignTag = async (tagRef, tagLabel) => {
    hideSuggest();
    if (scope === 'filter') {
      openFilterConfirm(tagRef, tagLabel);
      return;
    }
    const listingIds = selectedIds();
    if (listingIds.length === 0) return;
    const data = await post(assignUrl, { listingIds, ...tagRef });
    if (data) finishAssign(data);
  };

  const renderSuggest = () => {
    const raw = tagInput.value.trim();
    const term = raw.toLowerCase();
    const items = allTags.filter((tag) => tag.name.toLowerCase().includes(term)).slice(0, 20).map((tag) => {
      const item = el('div', 'suggest-item', [tag.name]);
      item.addEventListener('click', () => assignTag({ tagId: tag.id }, tag.name));
      return item;
    });
    if (raw !== '' && !allTags.some((tag) => tag.name.toLowerCase() === term)) {
      const create = el('div', 'suggest-create', [`Создать «${raw}»`]);
      create.addEventListener('click', () => assignTag({ name: raw }, raw));
      items.push(create);
    }
    suggestBox.replaceChildren(...items);
    suggestBox.classList.toggle('d-none', items.length === 0);
  };

  selectAll?.addEventListener('change', () => {
    rowBoxes.forEach((cb) => { cb.checked = selectAll.checked; });
    refreshPanel();
  });
  rowBoxes.forEach((cb) => cb.addEventListener('change', refreshPanel));
  panel.querySelector('[data-bulk-select-filter]')?.addEventListener('click', () => {
    scope = 'filter';
    refreshPanel();
  });
  panel.querySelector('[data-bulk-clear-filter]')?.addEventListener('click', () => {
    scope = 'page';
    rowBoxes.forEach((cb) => { cb.checked = false; });
    refreshPanel();
  });
  tagInput.addEventListener('input', renderSuggest);
  tagInput.addEventListener('focus', renderSuggest);
  tagInputWrap.addEventListener('click', () => tagInput.focus());
  document.addEventListener('click', (event) => {
    if (!tagInputWrap.contains(event.target)) hideSuggest();
  });

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-detach-tag]');
    if (!button) return;
    const data = await post(root.dataset.detachUrl, { listingIds: [button.dataset.listingId], tagId: button.dataset.tagId });
    if (data) reloadFromFirstPage();
  });

  refreshPanel();
};

export const initListingsIndex = () => {
  const root = document.querySelector('[data-listings-index]');
  if (!root) return;
  initFilterForm(root);
  initMapModal(root);
  initTags(root);
};
