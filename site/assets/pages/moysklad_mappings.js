/**
 * Маппинг МойСклад: пока идёт пересчёт отчёта или пакет ([data-mapping-autorefresh]), страница
 * перезагружается каждые 5 с. Не мешает работе: ждёт, пока закрыт диалог и нет отмеченных строк или ввода.
 */
const DELAY = 5000;

const busy = () => document.querySelector('.modal.show, .offcanvas.show, input[form="bulk-selected"]:checked')
  || document.activeElement?.matches('input:not([type=checkbox]):not([type=radio]), textarea, select');

export function initMoySkladMappings() {
  if (!document.querySelector('[data-mapping-autorefresh]')) {
    return;
  }
  const tick = () => (busy() ? setTimeout(tick, DELAY) : window.location.reload());
  setTimeout(tick, DELAY);
}
