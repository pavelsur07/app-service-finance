/**
 * Поиск контрагента в форме. Прогрессивное улучшение: без JS в DOM остаётся рабочий
 * <select> со всем company-scoped списком, скрипт лишь подменяет его полем поиска.
 *
 * Значение всегда пишется в тот же <select>, поэтому Symfony валидирует выбор по
 * списку choices, а существующие скрипты, читающие .value, продолжают работать.
 *
 * Список подсказок рендерится в document.body (портал) с position: fixed. Внутри формы
 * он попадал в стакинг-контекст блока условия и оказывался под кнопками «Удалить» и
 * «+ Добавить условие»: клик по первой подсказке удалял условие целиком. Точечный
 * z-index не лечит — при вложении формы в модалку или оффканвас дефект возвращается.
 */

const DEBOUNCE_MS = 250;
const MIN_QUERY_LENGTH = 2;
const VIEWPORT_MARGIN = 8;
const MIN_DROPDOWN_HEIGHT = 160;

function optionLabel(option) {
    const name = option.dataset.name || option.textContent.trim();
    const inn = option.dataset.inn || '';

    return inn ? `${name} · ${inn}` : name;
}

/**
 * Подсветка совпавшей подстроки без innerHTML: текст приходит из БД и от пользователя.
 */
function appendHighlighted(target, text, query) {
    const at = '' === query ? -1 : text.toLowerCase().indexOf(query.toLowerCase());

    if (at < 0) {
        target.appendChild(document.createTextNode(text));

        return;
    }

    target.appendChild(document.createTextNode(text.slice(0, at)));
    const mark = document.createElement('mark');
    mark.className = 'cp-mark';
    mark.textContent = text.slice(at, at + query.length);
    target.appendChild(mark);
    target.appendChild(document.createTextNode(text.slice(at + query.length)));
}

function initOnePicker(root) {
    if (root.dataset.cpReady === '1') {
        return;
    }
    root.dataset.cpReady = '1';

    const select = root.querySelector('[data-cp-select]');
    const searchWrap = root.querySelector('[data-cp-search-wrap]');
    const input = root.querySelector('[data-cp-input]');
    const clearButton = root.querySelector('[data-cp-clear]');

    if (!select || !searchWrap || !input) {
        return;
    }

    // Портал: список живёт в body, а не внутри блока формы.
    const results = document.createElement('div');
    results.className = 'cp-results list-group shadow';
    results.setAttribute('role', 'listbox');
    results.hidden = true;
    document.body.appendChild(results);

    const searchUrl = root.dataset.cpSearchUrl || '';
    let controller = null;
    let debounceTimer = null;
    let activeIndex = -1;
    let requestQuery = '';
    let lastQuery = '';

    select.classList.add('d-none');
    searchWrap.classList.remove('d-none');

    // Семантику required/disabled переносим на видимый input: скрытый select браузер
    // не сфокусирует, и подсказка валидации окажется привязана к невидимому элементу.
    if (select.required) {
        select.required = false;
        input.required = true;
    }
    if (select.disabled) {
        input.disabled = true;
    }

    const localOptions = Array.from(select.options).filter((option) => '' !== option.value);

    const selectedLabel = () => {
        const selected = select.options[select.selectedIndex];

        return selected && selected.value ? optionLabel(selected) : '';
    };

    const markSelectionState = () => {
        const hasSelection = '' !== select.value;
        root.classList.toggle('cp-has-selection', hasSelection);
        if (clearButton) {
            clearButton.disabled = !hasSelection;
        }
    };

    const isOpen = () => !results.hidden;

    const positionResults = () => {
        const rect = input.getBoundingClientRect();
        const spaceBelow = window.innerHeight - rect.bottom - VIEWPORT_MARGIN;
        const spaceAbove = rect.top - VIEWPORT_MARGIN;
        const openUp = spaceBelow < MIN_DROPDOWN_HEIGHT && spaceAbove > spaceBelow;

        results.style.left = `${rect.left}px`;
        results.style.width = `${rect.width}px`;
        results.style.maxHeight = `${Math.max(120, Math.min(320, openUp ? spaceAbove : spaceBelow))}px`;

        if (openUp) {
            results.style.top = 'auto';
            results.style.bottom = `${window.innerHeight - rect.top}px`;
        } else {
            results.style.bottom = 'auto';
            results.style.top = `${rect.bottom}px`;
        }
    };

    const showResults = () => {
        results.hidden = false;
        positionResults();
    };

    const hideResults = () => {
        results.hidden = true;
        results.replaceChildren();
        activeIndex = -1;
    };

    const clearSelection = () => {
        if ('' !== select.value) {
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        markSelectionState();
    };

    const syncInputFromSelect = () => {
        input.value = selectedLabel();
        input.setCustomValidity('');
        input.classList.remove('is-invalid');
        markSelectionState();
    };

    const choose = (id, label) => {
        // Выбрать можно только то, что есть в choices: иначе Symfony отклонит значение
        // при submit, и честнее сказать об этом сразу.
        const option = Array.from(select.options).find((candidate) => candidate.value === id);
        if (!option) {
            input.value = label;
            input.setCustomValidity('Обновите страницу: контрагент появился после её открытия.');

            return;
        }

        select.value = id;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        syncInputFromSelect();
        hideResults();
    };

    const renderMessage = (text, busy = false) => {
        results.replaceChildren();
        const item = document.createElement('div');
        item.className = `list-group-item text-muted${busy ? ' cp-loading' : ''}`;
        item.textContent = text;
        results.appendChild(item);
        showResults();
    };

    const renderResults = (items, query) => {
        results.replaceChildren();

        if (0 === items.length) {
            renderMessage('Ничего не найдено');

            return;
        }

        items.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action cp-option';
            button.dataset.cpOption = '';
            button.dataset.index = String(index);
            button.setAttribute('role', 'option');

            appendHighlighted(button, item.name, query);

            if (item.inn) {
                const inn = document.createElement('span');
                inn.className = 'text-muted ms-2 small';
                appendHighlighted(inn, item.inn, query);
                button.appendChild(inn);
            }

            // mousedown, а не click: список закрывается по mousedown вне него, и до
            // click элемент уже был бы скрыт.
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(item.id, button.textContent);
            });
            results.appendChild(button);
        });

        showResults();
    };

    const searchLocally = (query) => {
        if (query !== requestQuery) {
            return;
        }

        const needle = query.toLowerCase();
        const items = localOptions
            .filter((option) => optionLabel(option).toLowerCase().includes(needle))
            .slice(0, 20)
            .map((option) => ({
                id: option.value,
                name: option.dataset.name || option.textContent.trim(),
                inn: option.dataset.inn || null,
            }));

        renderResults(items, query);
    };

    const searchRemotely = async (query) => {
        controller = new AbortController();
        requestQuery = query;
        renderMessage('Поиск…', true);

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                searchLocally(query);

                return;
            }

            const items = await response.json();

            // Ответ мог прийти после того, как пользователь дописал запрос.
            if (query !== requestQuery) {
                return;
            }

            renderResults(items, query);
        } catch (error) {
            if ('AbortError' !== error.name) {
                searchLocally(query);
            }
        }
    };

    const runSearch = (query) => (searchUrl ? searchRemotely(query) : searchLocally(query));

    input.addEventListener('input', () => {
        const query = input.value.trim();
        lastQuery = query;
        window.clearTimeout(debounceTimer);
        // Отменяем сразу, а не в следующем debounce: иначе прежний ответ успевает
        // отрисоваться поверх нового ввода.
        controller?.abort();
        requestQuery = query;

        // Пока текст не совпадает с подписью выбранного варианта, выбора нет: раньше на
        // экране мог быть один контрагент, а в скрытом select оставался прежний.
        if (query !== selectedLabel()) {
            clearSelection();
            input.setCustomValidity('' === query ? '' : 'Выберите контрагента из списка.');
        } else {
            input.setCustomValidity('');
        }

        if (query.length < MIN_QUERY_LENGTH) {
            hideResults();

            return;
        }

        debounceTimer = window.setTimeout(() => runSearch(query), DEBOUNCE_MS);
    });

    input.addEventListener('keydown', (event) => {
        const options = Array.from(results.querySelectorAll('[data-cp-option]'));

        if ('Escape' === event.key) {
            hideResults();
            syncInputFromSelect();

            return;
        }

        if (0 === options.length) {
            return;
        }

        if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
            event.preventDefault();
            activeIndex = 'ArrowDown' === event.key
                ? Math.min(activeIndex + 1, options.length - 1)
                : Math.max(activeIndex - 1, 0);
            options.forEach((option, index) => option.classList.toggle('active', index === activeIndex));
            options[activeIndex].scrollIntoView({ block: 'nearest' });

            return;
        }

        if ('Enter' === event.key && activeIndex >= 0) {
            event.preventDefault();
            options[activeIndex].dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
        }
    });

    input.addEventListener('focus', () => {
        input.classList.remove('is-invalid');

        if (lastQuery.length >= MIN_QUERY_LENGTH && input.value.trim() === lastQuery && '' === select.value) {
            runSearch(lastQuery);
        }
    });

    clearButton?.addEventListener('click', () => {
        clearSelection();
        input.value = '';
        input.setCustomValidity('');
        input.classList.remove('is-invalid');
        hideResults();
    });

    // mousedown, а не click: список обязан закрыться до того, как событие дойдёт до
    // кнопки под ним. Именно поэтому «Удалить» получала клик по первой подсказке.
    document.addEventListener('mousedown', (event) => {
        if (!isOpen()) {
            return;
        }
        if (!root.contains(event.target) && !results.contains(event.target)) {
            hideResults();
        }
    });

    // Портал не двигается вместе с инпутом: пересчитываем позицию на скролле страницы,
    // прокрутке модалки (capture) и ресайзе.
    window.addEventListener('scroll', () => isOpen() && positionResults(), true);
    window.addEventListener('resize', () => isOpen() && positionResults());

    // Свободный текст без выбора не уходит на сервер: операция объявлена как точное
    // совпадение и работает по идентификатору контрагента, а не по введённой строке.
    input.closest('form')?.addEventListener('submit', (event) => {
        if ('' !== input.value.trim() && '' === select.value) {
            event.preventDefault();
            event.stopPropagation();
            hideResults();
            input.classList.add('is-invalid');
            input.setCustomValidity('Выберите контрагента из списка.');
            input.reportValidity();
            input.focus();
        }
    });

    syncInputFromSelect();
}

function initAll(root = document) {
    root.querySelectorAll('[data-cp-picker]').forEach(initOnePicker);
}

document.addEventListener('DOMContentLoaded', () => initAll());

// Формы-коллекции (операции документа, условия автоправила) добавляют строки динамически.
new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (1 !== node.nodeType) {
                return;
            }
            if (node.matches?.('[data-cp-picker]')) {
                initOnePicker(node);
            }
            initAll(node);
        });
    });
}).observe(document.documentElement, { childList: true, subtree: true });
