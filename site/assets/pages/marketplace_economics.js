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
            cells.forEach((cell, index) => {
                columns.children[index].style.width = `${cell.getBoundingClientRect().width}px`;
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

document.querySelectorAll('[data-economics-table]').forEach(initEconomicsTable);
