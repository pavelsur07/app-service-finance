/**
 * «Управление тегами» листингов (templates/marketplace/listings/tags.html.twig): создание в модале
 * с ошибкой под полем, переименование inline-полем, удаление и слияние — через _confirm_modal
 * (JSON-запрос шлёт модуль по confirm:submit). Адреса API — на корне [data-listing-tags].
 */
import { apiFetch } from '../api/client';
import { bootstrapModal } from '../bootstrap_modal.js';
import { el } from '../dom.js';
import { withId } from '../url_template.js';

// true при успехе; ошибка — alert, как и раньше.
const post = async (url, body = {}) => {
  try {
    await apiFetch(url, { method: 'POST', body });
    return true;
  } catch (e) {
    window.alert(e.status ? e.message : 'Ошибка сети');
    return false;
  }
};

const initCreateModal = (root) => {
  const modal = document.querySelector('[data-create-tag-modal]');
  const form = modal?.querySelector('form');
  if (!form) return;
  const nameInput = form.querySelector('input[name="name"]');
  const errorEl = form.querySelector('.invalid-feedback');
  const submitBtn = form.querySelector('button[type="submit"]');
  const showError = (message) => {
    errorEl.textContent = message;
    nameInput.classList.add('is-invalid');
  };

  modal.addEventListener('shown.bs.modal', () => nameInput.focus());
  modal.addEventListener('hidden.bs.modal', () => {
    form.reset();
    nameInput.classList.remove('is-invalid');
  });
  nameInput.addEventListener('input', () => nameInput.classList.remove('is-invalid'));
  // Ошибка показывается под полем, окно не закрывается.
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const name = nameInput.value.trim();
    if (name === '') {
      showError('Введите название тега.');
      return;
    }
    submitBtn.disabled = true;
    try {
      await apiFetch(root.dataset.createUrl, { method: 'POST', body: { name } });
      window.location.reload();
      return;
    } catch (e) {
      showError(e.status ? e.message : 'Ошибка сети');
    }
    submitBtn.disabled = false;
  });
};

// Переименование — inline-поле в ячейке тега; Enter/blur сохраняют, Escape и пустое имя — перезагрузка.
const startRename = (root, row) => {
  const currentName = row.dataset.tagName;
  const input = el('input', 'form-control form-control-sm');
  input.type = 'text';
  input.value = currentName;
  input.maxLength = Number(root.dataset.nameMaxLength);
  input.style.maxWidth = '260px';
  row.querySelector('[data-tag-cell]').replaceChildren(input);
  input.focus();
  input.select();

  const commit = async () => {
    const name = input.value.trim();
    if (name === '' || name === currentName) {
      window.location.reload();
      return;
    }
    if (await post(withId(root.dataset.renameUrl, row.dataset.tagId), { name })) window.location.reload();
  };
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') commit();
    if (event.key === 'Escape') window.location.reload();
  });
  input.addEventListener('blur', commit);
};

// Слияние — меню с остальными тегами как целями; каждый пункт — триггер диалога подтверждения.
const fillMergeMenus = (root) => {
  const allTags = JSON.parse(root.dataset.tags || '[]');
  root.querySelectorAll('tr[data-tag-id]').forEach((row) => {
    const menu = row.querySelector('[data-merge-menu]');
    if (!menu) return;
    menu.replaceChildren(...allTags.filter((tag) => tag.id !== row.dataset.tagId).map((target) => {
      const item = el('button', 'dropdown-item', [target.name]);
      item.type = 'button';
      Object.assign(item.dataset, {
        mergeTag: '',
        targetTagId: target.id,
        bsToggle: 'modal',
        bsTarget: root.dataset.confirmTarget,
        confirmAction: root.dataset.mergeUrl,
        confirmToken: root.dataset.confirmToken,
        confirmTitle: `Слить «${row.dataset.tagName}» в «${target.name}»?`,
        confirmBody: `Тег «${row.dataset.tagName}» будет удалён.`,
        confirmSubmit: 'Слить',
        confirmVariant: 'danger',
      });
      return item;
    }));
  });
};

export const initListingTags = () => {
  const root = document.querySelector('[data-listing-tags]');
  if (!root) return;
  initCreateModal(root);
  fillMergeMenus(root);

  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-rename-tag]');
    if (button) startRename(root, button.closest('tr'));
  });

  // Удаление и слияние: диалог подтверждён — закрываем его и шлём JSON сами.
  root.addEventListener('confirm:submit', async (event) => {
    const trigger = event.target;
    let body;
    if (trigger.matches('[data-delete-tag]')) {
      body = {};
    } else if (trigger.matches('[data-merge-tag]')) {
      body = { sourceTagId: trigger.closest('tr').dataset.tagId, targetTagId: trigger.dataset.targetTagId };
    } else {
      return;
    }
    event.preventDefault();
    bootstrapModal()?.getOrCreateInstance(event.detail.modal).hide();
    if (await post(trigger.dataset.confirmAction, body)) window.location.reload();
  });
};
