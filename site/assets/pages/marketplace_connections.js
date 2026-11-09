/**
 * «Подключения» маркетплейсов (templates/marketplace/connections.html.twig): модал «Новое подключение»
 * (тип Seller/Performance, поле Client-Id для Seller + Ozon, подмена form.action), подстановка action и
 * CSRF-токена в модалы синхронизации, переобработки и удаления, опрос статуса первичной загрузки.
 * Корень — [data-marketplace-connections]; ответ API статуса — только textContent.
 */
import { bootstrapModal } from '../bootstrap_modal.js';

const SPINNER = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>';

function initAddConnection() {
  const form = document.getElementById('form-add-connection');
  const marketplaceSelect = document.getElementById('connection-marketplace');
  const connectionTypeField = document.getElementById('field-connection-type');
  const perfRadio = document.getElementById('connection-type-performance');
  const sellerRadio = document.getElementById('connection-type-seller');
  const sellerBlock = form.querySelector('[data-connection-type-fields="seller"]');
  const perfBlock = form.querySelector('[data-connection-type-fields="performance"]');
  const sellerClientField = document.getElementById('field-seller-client-id');
  const sellerClientInput = document.getElementById('input-seller-client-id');

  const setDisabledAll = (block, disabled) => {
    block.querySelectorAll('input, select, textarea').forEach((el) => {
      el.disabled = disabled;
    });
  };

  const applyConnectionType = () => {
    if (perfRadio.checked) {
      sellerBlock.style.display = 'none';
      perfBlock.style.display = '';
      setDisabledAll(sellerBlock, true);
      setDisabledAll(perfBlock, false);
      form.action = form.dataset.performanceAction;
      return;
    }
    sellerBlock.style.display = '';
    perfBlock.style.display = 'none';
    setDisabledAll(sellerBlock, false);
    setDisabledAll(perfBlock, true);
    form.action = form.dataset.sellerAction;
    // Для Seller поле Client-Id — только при Ozon.
    if (marketplaceSelect.value === 'ozon') {
      sellerClientField.style.display = '';
      sellerClientInput.required = true;
    } else {
      sellerClientField.style.display = 'none';
      sellerClientInput.required = false;
      sellerClientInput.value = '';
    }
  };

  // Performance API есть только у Ozon: для остальных маркетплейсов блок «Тип подключения» скрыт,
  // а тип сбрасывается на Seller, чтобы форма ушла на правильный endpoint.
  const applyMarketplace = () => {
    if (marketplaceSelect.value === 'ozon') {
      connectionTypeField.style.display = '';
    } else {
      connectionTypeField.style.display = 'none';
      if (perfRadio.checked) sellerRadio.checked = true;
    }
    applyConnectionType();
  };

  marketplaceSelect.addEventListener('change', applyMarketplace);
  [sellerRadio, perfRadio].forEach((radio) => radio.addEventListener('change', applyConnectionType));
  // Маркетплейс не выбран → блок скрыт, тип = seller.
  applyMarketplace();
}

const onModalShow = (id, handler) => {
  document.getElementById(id).addEventListener('show.bs.modal', (event) => handler(event.relatedTarget));
};

const busyOnSubmit = (button, label) => {
  button.disabled = true;
  button.innerHTML = `${SPINNER}${label}`;
};

function initModals() {
  onModalShow('modal-sync-period', (trigger) => {
    const connectionId = trigger.getAttribute('data-connection-id');
    document.getElementById('form-sync-period').action = `/marketplace/connection/${connectionId}/sync-period`;
    document.getElementById('sync-period-token').value = trigger.getAttribute('data-token');
  });
  const syncPeriodForm = document.getElementById('form-sync-period');
  syncPeriodForm.addEventListener('submit', () => {
    busyOnSubmit(syncPeriodForm.querySelector('button[type="submit"]'), 'Синхронизация...');
  });

  onModalShow('modal-reprocess', (trigger) => {
    document.getElementById('reprocess-marketplace').value = trigger.getAttribute('data-marketplace');
  });
  document.getElementById('form-reprocess').addEventListener('submit', () => {
    busyOnSubmit(document.getElementById('btn-reprocess'), 'Обработка...');
    // Модал закрывается, чтобы во время обработки была видна страница.
    bootstrapModal()?.getInstance(document.getElementById('modal-reprocess'))?.hide();
  });

  onModalShow('modal-sync-realization', (trigger) => {
    document.getElementById('form-sync-realization').action = trigger.getAttribute('data-action');
    document.getElementById('sync-realization-token').value = trigger.getAttribute('data-token');
  });
  onModalShow('modal-delete-connection', (trigger) => {
    document.getElementById('form-delete-connection').action = trigger.getAttribute('data-action');
    document.getElementById('delete-connection-token').value = trigger.getAttribute('data-token');
  });
}

const statusLine = (text, className) => {
  const small = document.createElement('small');
  if (className) small.className = className;
  small.textContent = text;
  return small;
};

// Опрос статуса первичной загрузки; сетевая ошибка — опрос продолжается.
function pollInitialSync() {
  document.querySelectorAll('[data-status-url][data-synced="0"]').forEach((node) => {
    const interval = setInterval(() => {
      fetch(node.dataset.statusUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
          if (data.status === 'synced') {
            clearInterval(interval);
            node.replaceChildren(statusLine(`Последняя синхронизация: ${data.lastSyncAt}`));
          } else if (data.status === 'error') {
            clearInterval(interval);
            node.replaceChildren(statusLine(`Ошибка: ${data.lastSyncError ?? ''}`, 'text-danger'));
          }
        })
        .catch(() => {});
    }, 15000);
  });
}

export function initMarketplaceConnections() {
  if (!document.querySelector('[data-marketplace-connections]')) return;
  initAddConnection();
  initModals();
  pollInitialSync();
}
