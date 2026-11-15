import '../styles/pages/marketplace-economics.css';

// Локальное поведение R1/R2/R10: исходная таблица остаётся источником размеров и ссылок.
function initEconomicsTable(card) {
    const scroll = card.querySelector('[data-economics-scroll]');
    const table = scroll?.querySelector('table');
    const head = table?.querySelector('thead');
    if (!scroll || !table || !head) return;

    const fixed = document.createElement('div');
    fixed.className = 'me-fixed-header';
    fixed.hidden = true;
    fixed.setAttribute('aria-hidden', 'true');
    const copy = table.cloneNode(false);
    const columns = document.createElement('colgroup');
    const cells = [...head.querySelectorAll('th')];
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
    function update() {
        frame = 0;
        const rect = scroll.getBoundingClientRect();
        const tableRect = table.getBoundingClientRect();
        const height = head.getBoundingClientRect().height;
        if (measure) {
            cells.forEach((cell, index) => {
                columns.children[index].style.width = `${cell.getBoundingClientRect().width}px`;
            });
            copy.style.width = `${tableRect.width}px`;
            track.style.width = `${scroll.scrollWidth}px`;
            measure = false;
        }
        for (const element of [fixed, bar]) {
            element.style.left = `${rect.left}px`;
            element.style.width = `${scroll.clientWidth}px`;
        }
        fixed.hidden = tableRect.top >= 0 || tableRect.bottom <= height;
        bar.hidden = scroll.scrollWidth <= scroll.clientWidth || rect.top >= window.innerHeight - 16 || rect.bottom <= window.innerHeight;
        fixed.scrollLeft = scroll.scrollLeft;
        bar.scrollLeft = scroll.scrollLeft;
    }
    function schedule() {
        if (!frame) frame = requestAnimationFrame(update);
    }
    function resize() {
        measure = true;
        schedule();
    }
    function syncBar() {
        if (!bar.hidden && Math.abs(scroll.scrollLeft - bar.scrollLeft) > 1) scroll.scrollLeft = bar.scrollLeft;
        schedule();
    }
    scroll.addEventListener('scroll', schedule, { passive: true });
    bar.addEventListener('scroll', syncBar, { passive: true });
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', resize);
    const observer = new ResizeObserver(resize);
    observer.observe(scroll);
    observer.observe(table);
    // Слушатели живут вместе с документом и сохраняются при возврате из bfcache.
    window.addEventListener('pageshow', resize);
    schedule();
}

document.querySelectorAll('[data-economics-table]').forEach(initEconomicsTable);
