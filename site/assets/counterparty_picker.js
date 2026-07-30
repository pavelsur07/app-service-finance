/**
 * Поиск контрагента в форме. Прогрессивное улучшение: без JS в DOM остаётся рабочий
 * <select> со всем company-scoped списком, скрипт лишь подменяет его полем поиска.
 *
 * Значение всегда пишется в тот же <select>, поэтому Symfony валидирует выбор по
 * списку choices, а существующие скрипты, читающие .value, продолжают работать.
 */

const DEBOUNCE_MS = 250;
const MIN_QUERY_LENGTH = 2;

function optionLabel(option) {
    const name = option.dataset.name || option.textContent.trim();
    const inn = option.dataset.inn || '';

    return inn ? `${name} · ${inn}` : name;
}

function initOnePicker(root) {
    if (root.dataset.cpReady === '1') {
        return;
    }
    root.dataset.cpReady = '1';

    const select = root.querySelector('[data-cp-select]');
    const searchWrap = root.querySelector('[data-cp-search-wrap]');
    const input = root.querySelector('[data-cp-input]');
    const results = root.querySelector('[data-cp-results]');
    const clearButton = root.querySelector('[data-cp-clear]');

    if (!select || !searchWrap || !input || !results) {
        return;
    }

    const searchUrl = root.dataset.cpSearchUrl || '';
    let controller = null;
    let debounceTimer = null;
    let activeIndex = -1;
    let requestQuery = '';

    // JS доступен — прячем select и показываем поиск. Семантику required/disabled
    // переносим на видимый input, иначе браузер не сможет сфокусировать невалидное
    // поле и подсказка окажется привязана к скрытому элементу.
    select.classList.add('d-none');
    searchWrap.classList.remove('d-none');

    if (select.required) {
        select.required = false;
        input.required = true;
    }
    if (select.disabled) {
        input.disabled = true;
    }

    const localOptions = Array.from(select.options).filter((option) => option.value !== '');

    const selectedLabel = () => {
        const selected = select.options[select.selectedIndex];

        return selected && selected.value ? optionLabel(selected) : '';
    };

    const syncInputFromSelect = () => {
        input.value = selectedLabel();
        input.setCustomValidity('');
        if (clearButton) {
            clearButton.disabled = !select.value;
        }
    };

    const clearSelection = () => {
        if (select.value !== '') {
            select.value = '';
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (clearButton) {
            clearButton.disabled = true;
        }
    };

    const hideResults = () => {
        results.classList.add('d-none');
        results.innerHTML = '';
        activeIndex = -1;
    };

    const select_ = (id, label) => {
        // Выбирать можно только то, что есть в списке choices: иначе Symfony
        // отклонит значение при submit, и лучше сказать об этом сразу.
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

    const renderResults = (items) => {
        results.innerHTML = '';

        if (items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item text-muted';
            empty.textContent = 'Ничего не найдено';
            results.appendChild(empty);
            results.classList.remove('d-none');

            return;
        }

        items.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.dataset.cpOption = '';
            button.dataset.index = String(index);
            button.textContent = item.inn ? `${item.name} · ${item.inn}` : item.name;
            button.addEventListener('click', () => select_(item.id, button.textContent));
            results.appendChild(button);
        });

        results.classList.remove('d-none');
    };

    const searchLocally = (query) => {
        // Запрос мог устареть, пока шёл fetch: иначе старые локальные результаты
        // отрисуются поверх нового ввода.
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

        renderResults(items);
    };

    const searchRemotely = async (query) => {
        controller = new AbortController();
        requestQuery = query;

        try {
            const response = await fetch(`${searchUrl}?q=${encodeURIComponent(query)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                // Endpoint недоступен — не оставляем пользователя без выбора.
                searchLocally(query);

                return;
            }

            const items = await response.json();

            // Ответ мог прийти после того, как пользователь дописал запрос.
            if (query !== requestQuery) {
                return;
            }

            renderResults(items);
        } catch (error) {
            if (error.name !== 'AbortError') {
                searchLocally(query);
            }
        }
    };

    input.addEventListener('input', () => {
        const query = input.value.trim();
        window.clearTimeout(debounceTimer);
        // Отменяем сразу, а не в следующем debounce: иначе прежний ответ успевает
        // отрисоваться поверх нового ввода.
        controller?.abort();
        requestQuery = query;

        // Пока текст не совпадает с выбранным вариантом, выбора нет: раньше на экране
        // мог быть один контрагент, а в скрытом select оставался прежний.
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

        debounceTimer = window.setTimeout(
            () => (searchUrl ? searchRemotely(query) : searchLocally(query)),
            DEBOUNCE_MS,
        );
    });

    input.addEventListener('keydown', (event) => {
        const options = Array.from(results.querySelectorAll('[data-cp-option]'));

        if (event.key === 'Escape') {
            hideResults();
            syncInputFromSelect();

            return;
        }

        if (options.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            activeIndex = event.key === 'ArrowDown'
                ? Math.min(activeIndex + 1, options.length - 1)
                : Math.max(activeIndex - 1, 0);
            options.forEach((option, index) => option.classList.toggle('active', index === activeIndex));
            options[activeIndex].scrollIntoView({ block: 'nearest' });

            return;
        }

        if (event.key === 'Enter' && activeIndex >= 0) {
            event.preventDefault();
            options[activeIndex].click();
        }
    });

    clearButton?.addEventListener('click', () => {
        clearSelection();
        input.value = '';
        input.setCustomValidity('');
        hideResults();
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            hideResults();
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
            if (node.nodeType !== 1) {
                return;
            }
            if (node.matches?.('[data-cp-picker]')) {
                initOnePicker(node);
            }
            initAll(node);
        });
    });
}).observe(document.documentElement, { childList: true, subtree: true });
