/**
 * Вид «поле формы» окна выбора: прогрессивное улучшение над <select>. Без JS в DOM
 * остаётся рабочий <select> со всем company-scoped списком, скрипт подменяет его полем
 * поиска. Значение всегда пишется в тот же <select>: Symfony валидирует выбор по choices,
 * а существующие скрипты, читающие .value и слушающие change, продолжают работать.
 */
import { createListbox, createSearch, positionPortal } from './picker_core.js';

const LOCAL_LIMIT = 20;

/**
 * @param {object} parts   select, input, searchWrap, clearButton — элементы поля
 * @param {object} options searchUrl, minQuery, adapter, rowFromOption(option),
 *                         labelFromOption(option), texts {invalid, stale}
 */
export function initPickerField(root, parts, options) {
    const { select, input, searchWrap, clearButton } = parts;
    const { searchUrl, minQuery, adapter, rowFromOption, labelFromOption, texts } = options;

    // Портал: список живёт в body, а не внутри блока формы (см. positionPortal).
    const portal = document.createElement('div');
    portal.className = 'vf-pick-portal';
    portal.hidden = true;
    document.body.appendChild(portal);

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

    const localItems = Array.from(select.options)
        .filter((option) => '' !== option.value)
        .map((option) => ({ item: adapter(rowFromOption(option)), text: labelFromOption(option).toLowerCase() }));

    const selectedLabel = () => {
        const selected = select.options[select.selectedIndex];

        return selected && selected.value ? labelFromOption(selected) : '';
    };

    const isOpen = () => !portal.hidden;

    const show = () => {
        portal.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        positionPortal(portal, input);
    };

    const hide = () => {
        portal.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        listbox.clear();
    };

    const markSelectionState = () => {
        const hasSelection = '' !== select.value;
        root.classList.toggle('cp-has-selection', hasSelection);
        if (clearButton) {
            clearButton.disabled = !hasSelection;
        }
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

    const choose = (item) => {
        // Выбрать можно только то, что есть в choices: иначе Symfony отклонит значение
        // при submit, и честнее сказать об этом сразу.
        const option = Array.from(select.options).find((candidate) => candidate.value === item.id);
        if (!option) {
            input.value = item.hint ? `${item.label} · ${item.hint}` : item.label;
            input.setCustomValidity(texts.stale);

            return;
        }

        select.value = item.id;
        select.dispatchEvent(new Event('change', { bubbles: true }));
        syncInputFromSelect();
        hide();
    };

    const listbox = createListbox(portal, input, { onChoose: choose });

    const render = (items, query) => {
        listbox.render(items, { query, emptyText: 'Ничего не найдено' });
        show();
    };

    // Поиск по <option> — без search_url и когда API недоступен: форма остаётся заполнимой.
    const searchLocally = (query) => {
        const needle = query.toLowerCase();
        render(localItems.filter((entry) => entry.text.includes(needle)).slice(0, LOCAL_LIMIT).map((entry) => entry.item), query);
    };

    const search = searchUrl
        ? createSearch({
            url: searchUrl,
            adapter,
            onLoading: () => {
                listbox.loading();
                show();
            },
            onResults: render,
            onError: searchLocally,
        })
        : null;

    const runSearch = (query) => (search ? search.run(query) : searchLocally(query));

    input.setAttribute('aria-expanded', 'false');

    input.addEventListener('input', () => {
        const query = input.value.trim();
        lastQuery = query;
        search?.cancel();

        // Пока текст не совпадает с подписью выбранного варианта, выбора нет: раньше на
        // экране мог быть один контрагент, а в скрытом select оставался прежний.
        if (query !== selectedLabel()) {
            clearSelection();
            input.setCustomValidity('' === query ? '' : texts.invalid);
        } else {
            input.setCustomValidity('');
        }

        if (query.length < minQuery) {
            hide();

            return;
        }

        if (search) {
            search.schedule(query);
        } else {
            searchLocally(query);
        }
    });

    input.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) {
            // Открытый список закрывается сам, а не вместе с модалкой формы.
            if (isOpen()) {
                event.stopPropagation();
            }
            search?.cancel();
            hide();
            syncInputFromSelect();

            return;
        }

        listbox.handleKey(event);
    });

    input.addEventListener('focus', () => {
        input.classList.remove('is-invalid');

        if (lastQuery.length >= minQuery && input.value.trim() === lastQuery && '' === select.value) {
            runSearch(lastQuery);
        }
    });

    clearButton?.addEventListener('click', () => {
        search?.cancel();
        clearSelection();
        input.value = '';
        input.setCustomValidity('');
        input.classList.remove('is-invalid');
        hide();
    });

    // mousedown, а не click: список обязан закрыться до того, как событие дойдёт до
    // кнопки под ним. Иначе «Удалить» условия получала клик по первой подсказке.
    document.addEventListener('mousedown', (event) => {
        if (isOpen() && !root.contains(event.target) && !portal.contains(event.target)) {
            hide();
        }
    });

    // Портал не двигается вместе с инпутом: пересчитываем позицию на скролле страницы,
    // прокрутке модалки (capture) и ресайзе.
    window.addEventListener('scroll', () => isOpen() && positionPortal(portal, input), true);
    window.addEventListener('resize', () => isOpen() && positionPortal(portal, input));

    // Свободный текст без выбора не уходит на сервер: значение поля — идентификатор,
    // а не введённая строка.
    input.closest('form')?.addEventListener('submit', (event) => {
        if ('' !== input.value.trim() && '' === select.value) {
            event.preventDefault();
            event.stopPropagation();
            hide();
            input.classList.add('is-invalid');
            input.setCustomValidity(texts.invalid);
            input.reportValidity();
            input.focus();
        }
    });

    syncInputFromSelect();
}
