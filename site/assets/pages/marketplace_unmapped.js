/**
 * Непривязанные листинги (templates/marketplace/unmapped/index.html.twig): кнопка листинга открывает
 * модал привязки со списком продуктов (/api/products), «Привязать» шлёт POST и перезагружает страницу;
 * поиск фильтрует строки таблицы. Без Tabler модал показывается и закрывается вручную.
 * Корень — [data-marketplace-unmapped].
 */
import { bootstrapModal } from '../bootstrap_modal.js';

const loadProducts = async () => {
  const select = document.getElementById('product-select');
  select.innerHTML = '<option value="">Загрузка...</option>';
  try {
    const response = await fetch('/api/products');
    const products = await response.json();
    select.innerHTML = '<option value="">-- Выберите продукт --</option>';
    products.forEach((product) => {
      const option = document.createElement('option');
      option.value = product.id;
      option.textContent = `${product.sku} - ${product.name}`;
      select.appendChild(option);
    });
  } catch {
    select.innerHTML = '<option value="">Ошибка загрузки</option>';
  }
};

export function initMarketplaceUnmapped() {
  if (!document.querySelector('[data-marketplace-unmapped]')) return;
  const mapModalElement = document.getElementById('mapListingModal');
  const BootstrapModal = bootstrapModal();
  let currentListingId = null;

  const showMapModal = () => {
    if (!mapModalElement) return;
    if (!BootstrapModal) {
      mapModalElement.classList.add('show');
      mapModalElement.style.display = 'block';
      mapModalElement.removeAttribute('aria-hidden');
      mapModalElement.setAttribute('aria-modal', 'true');
      return;
    }
    BootstrapModal.getOrCreateInstance(mapModalElement).show();
  };

  if (mapModalElement && !BootstrapModal) {
    mapModalElement.querySelectorAll('[data-bs-dismiss="modal"]').forEach((button) => {
      button.addEventListener('click', () => {
        mapModalElement.classList.remove('show');
        mapModalElement.style.display = 'none';
        mapModalElement.setAttribute('aria-hidden', 'true');
        mapModalElement.removeAttribute('aria-modal');
      });
    });
  }

  document.querySelectorAll('.map-listing-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      currentListingId = btn.dataset.listingId;
      document.getElementById('modal-listing-sku').textContent = btn.dataset.listingSku;
      loadProducts();
      showMapModal();
    });
  });

  const confirmBtn = document.getElementById('confirm-mapping-btn');
  confirmBtn?.addEventListener('click', async () => {
    const productId = document.getElementById('product-select').value;
    const errorDiv = document.getElementById('mapping-error');
    errorDiv.classList.add('d-none');
    if (!productId) {
      errorDiv.textContent = 'Выберите продукт';
      errorDiv.classList.remove('d-none');
      return;
    }
    const fail = (message) => {
      errorDiv.textContent = message;
      errorDiv.classList.remove('d-none');
      confirmBtn.disabled = false;
      confirmBtn.textContent = 'Привязать';
    };
    confirmBtn.disabled = true;
    confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Привязка...';
    try {
      const response = await fetch(`/api/marketplace/listings/${currentListingId}/map`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ productId }),
      });
      const data = await response.json();
      if (response.ok) {
        window.location.reload();
      } else {
        fail(data.error || 'Не удалось привязать листинг');
      }
    } catch {
      fail('Ошибка сети');
    }
  });

  document.getElementById('search-input')?.addEventListener('input', (event) => {
    const searchTerm = event.currentTarget.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach((row) => {
      row.style.display = row.textContent.toLowerCase().includes(searchTerm) ? '' : 'none';
    });
  });
}
