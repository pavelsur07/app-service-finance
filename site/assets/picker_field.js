/**
 * Вид «поле формы» окна выбора: прогрессивное улучшение над <select>. Без JS в DOM
 * остаётся рабочий <select> со всем company-scoped списком, скрипт подменяет его полем
 * поиска. Значение всегда пишется в тот же <select>: Symfony валидирует выбор по choices,
 * а существующие скрипты, читающие .value и слушающие change, продолжают работать.
 */
import { createListbox, createSearch, positionPortal } from './picker_core.js';

const LOCAL_LIMIT = 20;
// Окно browse не уже поповера пилюли: в узкой ячейке строки операции название не читалось бы.
const BROWSE_MIN_WIDTH = 360;

/**
 * @param {object} parts   select, input, searchWrap, clearButton — элементы поля
 * @param {object} options searchUrl, minQuery, adapter, rowFromOption(option),
 *                         labelFromOption(option), searchTextFromOption(option)?, texts {invalid, stale},
 *                         browse? — окно как у пилюли фильтра: до ввода выбранный и первые
 *                         LOCAL_LIMIT неархивных из <option>, группы и иконки адаптера,
 *                         hint? — подсказка над списком до ввода
 */
export function initPickerField(root, parts, options) {
    const { select, input, searchWrap, clearButton } = parts;
    const { searchUrl, minQuery, adapter, rowFromOption, labelFromOption, texts } = options;
    const searchTextFromOption = options.searchTextFromOption ?? labelFromOption;
    const browse = Boolean(options.browse);

    // Портал: список живёт в body, а не внутри блока формы (см. positionPortal).
    const portal = document.createElement('div');
    portal.className = 'vf-pick-portal';
    portal.hidden = true;
    portal.classList.toggle('vf-pick-portal--browse', browse);
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
        .map((option) => ({ item: adapter(rowFromOption(option)), text: searchTextFromOption(option).toLowerCase() }));

    const selectedLabel = () => {
        const selected = select.options[select.selectedIndex];

        return selected && selected.value ? labelFromOption(selected) : '';
    };

    const isOpen = () => !portal.hidden;

    // browse: окно по ширине всей группы поля (с кнопками), но не уже BROWSE_MIN_WIDTH.
    const place = () => (browse
        ? positionPortal(portal, searchWrap, { minWidth: BROWSE_MIN_WIDTH })
        : positionPortal(portal, input));

    const show = () => {
        portal.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        place();
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

    const listbox = createListbox(portal, input, { onChoose: choose, grouped: browse, icons: browse });

    // Подсказка над списком до ввода — как у пилюли; ядро кладёт список в начало портала.
    const hint = browse && options.hint ? document.createElement('div') : null;
    if (hint) {
        hint.className = 'vf-pick-pop__hint';
        hint.textContent = options.hint;
        portal.prepend(hint);
    }
    const setHint = (visible) => {
        if (hint) {
            hint.hidden = !visible;
        }
    };

    const render = (items, query) => {
        setHint(false);
        listbox.render(items, { query, selected: select.value, emptyText: 'Ничего не найдено' });
        show();
    };

    // До ввода: выбранный первым (даже архивный — иначе выбор не виден), затем первые
    // неархивные по порядку <option> (сервер отдаёт их по алфавиту).
    const initialItems = () => {
        const selected = select.options[select.selectedIndex];
        const head = selected && '' !== selected.value ? [selected] : [];
        const rest = Array.from(select.options)
            .filter((option) => '' !== option.value && option !== selected && '1' !== option.dataset.archived)
            .slice(0, LOCAL_LIMIT);

        return head.concat(rest).map((option) => adapter(rowFromOption(option)));
    };

    const showInitial = () => {
        search?.cancel();
        setHint(true);
        listbox.render(initialItems(), { selected: select.value });
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
                setHint(false);
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
            if (browse) {
                showInitial();
            } else {
                hide();
            }

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

        if (browse && !isOpen() && 'ArrowDown' === event.key) {
            event.preventDefault();
            showInitial();

            return;
        }

        listbox.handleKey(event);
    });

    input.addEventListener('focus', () => {
        input.classList.remove('is-invalid');

        if (lastQuery.length >= minQuery && input.value.trim() === lastQuery && '' === select.value) {
            runSearch(lastQuery);

            return;
        }

        // browse: фокус открывает список, подпись выбранного выделена — ввод её заменяет.
        if (browse) {
            input.select();
            showInitial();
        }
    });

    // browse открывается на фокусе, поэтому Tab к соседнему полю обязан его закрыть, иначе окно
    // висит поверх формы. Без relatedTarget (клик по полосе прокрутки окна) не закрываем:
    // клик мимо закрывает обработчик mousedown ниже.
    input.addEventListener('blur', (event) => {
        if (browse && event.relatedTarget && !portal.contains(event.relatedTarget)) {
            search?.cancel();
            hide();
        }
    });

    // Клик по уже сфокусированному полю после Esc или выбора снова открывает список.
    input.addEventListener('click', () => {
        if (browse && !isOpen()) {
            showInitial();
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
    window.addEventListener('scroll', () => isOpen() && place(), true);
    window.addEventListener('resize', () => isOpen() && place());

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
