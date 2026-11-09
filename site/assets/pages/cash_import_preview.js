/**
 * Предпросмотр импорта ДДС (templates/cash/file_import_preview.html.twig): счётчик выбранных описаний
 * учитывает и выбор на других страницах (data-selected-count). Корень — [data-description-selection].
 */
export function initCashImportPreview() {
  const form = document.querySelector('[data-description-selection]');
  if (!form) return;
  const checkboxes = Array.from(form.querySelectorAll('input[name="selected[]"]'));
  const output = form.querySelector('[data-description-count]');
  const checked = () => checkboxes.filter((input) => input.checked).length;
  const offPage = Number(form.dataset.selectedCount) - checked();
  const update = () => { output.textContent = String(offPage + checked()); };
  checkboxes.forEach((input) => input.addEventListener('change', update));
  update();
}
