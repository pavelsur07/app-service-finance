import '../styles/pages/marketplace-economics.css';

// Локальное поведение R1/R2/R10: исходная таблица остаётся источником размеров и ссылок.
function initEconomicsTable(card) {
    const scroll = card.querySelector('[data-economics-scroll]');
    const table = scroll?.querySelector('table');
    const head = table?.querySelector('thead');
    if (!scroll || !table || !head) return;
    const isSku = table.classList.contains('me-sku-table');

    const fixed = document.createElement('div');
    fixed.className = 'me-fixed-header';
    fixed.hidden = true;
    fixed.setAttribute('aria-hidden', 'true');
    const copy = table.cloneNode(false);
    const columns = document.createElement('colgroup');
    const cells = [...head.querySelectorAll('th')];
    const values = isSku ? table.querySelectorAll('.me-sku-value') : [];
    cells.forEach(() => columns.append(document.createElement('col')));
    copy.append(columns, head.cloneNode(true));
    copy.querySelectorAll('a').forEach((link) => link.tabIndex = -1);
    fixed.append(copy);

    const bar = document.createElement('div');
    bar.className = 'me-floating-scroll';
    bar.hidden = true;
    bar.tabIndex = 0;
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Горизонтальная прокрутка: ' + scroll.getAttribute('aria-label'));
    const track = document.createElement('div');
    bar.append(track);
    document.body.append(fixed, bar);

    let frame = 0;
    let measure = true;
    let syncedBarLeft = 0;
    function update() {
        frame = 0;
        const rect = scroll.getBoundingClientRect();
        const tableRect = table.getBoundingClientRect();
        const height = head.getBoundingClientRect().height;
        if (measure) {
            // скрытые колонки (окно «Колонки») выпадают из раскладки: ширины — по видимым ячейкам подряд
            const visible = cells.filter((cell) => cell.getClientRects().length > 0);
            [...columns.children].forEach((col, index) => {
                col.style.width = visible[index] ? `${visible[index].getBoundingClientRect().width}px` : '0';
            });
            if (isSku) {
                values.forEach((value) => {
                    const disclosure = value.closest('details');
                    if (disclosure?.open) return;

                    const truncated = value.scrollWidth > value.clientWidth;
                    if (truncated && !disclosure) {
                        const details = document.createElement('details');
                        const summary = document.createElement('summary');
                        value.replaceWith(details);
                        summary.append(value);
                        details.append(summary);
                    } else if (!truncated && disclosure) {
                        if (disclosure.contains(document.activeElement)) {
                            disclosure.addEventListener('focusout', resize, { once: true });
                        } else {
                            disclosure.replaceWith(value);
                        }
                    }
                });
                const skuWidth = columns.firstElementChild.style.width;
                table.style.setProperty('--me-sku-offset', skuWidth);
                copy.style.setProperty('--me-sku-offset', skuWidth);
            }
            copy.style.width = `${tableRect.width}px`;
            track.style.width = `${scroll.scrollWidth}px`;
            measure = false;
        }
        for (const element of [fixed, bar]) {
            element.style.left = `${rect.left}px`;
            element.style.width = `${scroll.clientWidth}px`;
        }
        if (isSku) {
            fixed.hidden = tableRect.top >= 0 || tableRect.bottom <= 0;
            fixed.style.top = Math.min(0, tableRect.bottom - height) + 'px';
            const nativeBarTop = rect.bottom - (scroll.offsetHeight - scroll.clientHeight);
            bar.hidden = scroll.scrollWidth <= scroll.clientWidth || rect.top >= window.innerHeight || nativeBarTop < window.innerHeight;
        } else {
            fixed.hidden = tableRect.top >= 0 || tableRect.bottom <= height;
            bar.hidden = scroll.scrollWidth <= scroll.clientWidth || rect.top >= window.innerHeight - 16 || rect.bottom <= window.innerHeight;
        }
        fixed.scrollLeft = scroll.scrollLeft;
        bar.scrollLeft = scroll.scrollLeft;
        syncedBarLeft = bar.scrollLeft;
    }
    function schedule() {
        if (!frame) frame = requestAnimationFrame(update);
    }
    function resize() {
        measure = true;
        schedule();
    }
    function syncBar() {
        const barMoved = isSku ? bar.scrollLeft !== syncedBarLeft : Math.abs(scroll.scrollLeft - bar.scrollLeft) > 1;
        if (!bar.hidden && barMoved) scroll.scrollLeft = bar.scrollLeft;
        schedule();
    }
    scroll.addEventListener('scroll', schedule, { passive: true });
    bar.addEventListener('scroll', syncBar, { passive: true });
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', resize);
    const observer = new ResizeObserver(resize);
    observer.observe(scroll);
    observer.observe(table);
    if (isSku) table.addEventListener('toggle', resize, true);
    // Слушатели живут вместе с документом и сохраняются при возврате из bfcache.
    window.addEventListener('pageshow', resize);
    schedule();
}

// Окно «Колонки» R1: скрытые колонки — в localStorage, новые колонки по умолчанию видны.
const COLUMNS_KEY = 'marketplace-economics:hidden-columns';

function initColumnPicker(picker) {
    const style = document.createElement('style');
    document.head.append(style);
    // только галочки этого вида: обязательные колонки не скрываются, выбор другого вида сохраняется
    const boxes = [...picker.querySelectorAll('input[type="checkbox"]:not([disabled])')];
    const ids = boxes.map((box) => box.value);
    let hidden = [];
    try {
        const stored = JSON.parse(localStorage.getItem(COLUMNS_KEY) || '[]');
        hidden = Array.isArray(stored) ? stored.filter((id) => typeof id === 'string') : [];
    } catch {
        hidden = [];
    }
    function apply() {
        // фиксированная шапка — копия таблицы с тем же классом, правило скрывает колонку и в ней
        style.textContent = hidden.filter((id) => ids.includes(id))
            .map((id) => `.me-sku-table [data-column="${CSS.escape(id)}"]{display:none}`).join('');
        boxes.forEach((box) => box.checked = !hidden.includes(box.value));
    }
    boxes.forEach((box) => box.addEventListener('change', () => {
        hidden = [...hidden.filter((id) => !ids.includes(id)), ...boxes.filter((b) => !b.checked).map((b) => b.value)];
        try {
            localStorage.setItem(COLUMNS_KEY, JSON.stringify(hidden));
        } catch {
            // приватный режим — выбор действует до перезагрузки
        }
        apply();
    }));
    document.addEventListener('click', (event) => {
        if (picker.open && !picker.contains(event.target)) picker.open = false;
    });
    picker.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            picker.open = false;
            picker.querySelector('summary').focus();
        }
    });
    apply();
}

document.querySelectorAll('[data-economics-columns]').forEach(initColumnPicker);
document.querySelectorAll('[data-economics-table]').forEach(initEconomicsTable);
