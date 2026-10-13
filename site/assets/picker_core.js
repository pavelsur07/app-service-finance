/**
 * Ядро окна выбора из справочника (entity picker): поиск, список, клавиатура, состояния.
 * Оформление — два вида поверх ядра: поле формы (picker_field.js) и пилюля фильтра
 * (picker_pill.js). Контракт и пример подключения справочника —
 * docs/design-system/components/entity-picker.md.
 *
 * Ядро работает с элементом {id, label, hint?, group?, icon?}. Ответ API приводится к нему
 * адаптером: новый справочник = свой адаптер в `adapters` + свой URL, без копии кода.
 * Текст из БД попадает в DOM только через textContent.
 */

export const DEBOUNCE_MS = 250;
export const DEFAULT_MIN_QUERY = 2;

const VIEWPORT_MARGIN = 8;
const MIN_PORTAL_HEIGHT = 160;

/** Адаптеры ответа API → элемент ядра. Ключ — значение data-picker-adapter. */
export const adapters = {
    // GET /api/counterparties/search: {id, name, inn, kpp, type}. Типы — App\Company\Enum\CounterpartyType.
    counterparty: (row) => {
        const legal = 'LEGAL_ENTITY' === row.type;
        const known = Boolean(row.type);

        return {
            id: String(row.id),
            label: row.name,
            hint: row.inn || '',
            group: known ? (legal ? 'Организации' : 'ИП и физические лица') : '',
            icon: known ? (legal ? 'building' : 'user') : '',
        };
    },
};

const identity = (row) => ({ id: String(row.id), label: row.label, hint: row.hint || '', group: row.group || '', icon: row.icon || '' });

/** Общий атрибутный контракт data-picker-*. */
export function readConfig(el) {
    const data = el.dataset;
    const minQuery = Number.parseInt(data.pickerMinQuery ?? '', 10);

    return {
        searchUrl: data.pickerSearchUrl || '',
        minQuery: Number.isNaN(minQuery) ? DEFAULT_MIN_QUERY : Math.max(1, minQuery),
        param: data.pickerParam || '',
        value: data.pickerValue || '',
        label: data.pickerLabel || '',
        emptyText: data.pickerEmptyText || 'Ничего не нашли.',
        adapter: adapters[data.pickerAdapter] ?? identity,
    };
}

/** Подсветка совпавшей подстроки без innerHTML: текст приходит из БД и от пользователя. */
export function appendHighlighted(target, text, query) {
    const at = '' === query ? -1 : text.toLowerCase().indexOf(query.toLowerCase());

    if (at < 0) {
        target.appendChild(document.createTextNode(text));

        return;
    }

    target.appendChild(document.createTextNode(text.slice(0, at)));
    const mark = document.createElement('mark');
    mark.className = 'vf-pick-mark';
    mark.textContent = text.slice(at, at + query.length);
    target.appendChild(mark);
    target.appendChild(document.createTextNode(text.slice(at + query.length)));
}

/**
 * Поиск на сервере: debounce, отмена прежнего запроса и отсев ответа, пришедшего после
 * того, как пользователь дописал запрос. В URL уходит только q — компания на сервере.
 */
export function createSearch({ url, adapter, onLoading, onResults, onError }) {
    let timer = null;
    let controller = null;
    let current = null;

    const cancel = () => {
        window.clearTimeout(timer);
        controller?.abort();
        controller = null;
        current = null;
    };

    const run = async (query) => {
        cancel();
        current = query;
        controller = new AbortController();
        onLoading(query);

        try {
            const response = await fetch(`${url}?q=${encodeURIComponent(query)}`, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const rows = await response.json();

            if (query !== current) {
                return;
            }

            onResults(Array.isArray(rows) ? rows.map(adapter) : [], query);
        } catch (error) {
            if ('AbortError' !== error.name && query === current) {
                onError(query);
            }
        }
    };

    return {
        // Прежний запрос отменяется сразу, а не в следующем debounce: иначе его ответ
        // успевает отрисоваться поверх нового ввода.
        schedule(query) {
            cancel();
            current = query;
            timer = window.setTimeout(() => run(query), DEBOUNCE_MS);
        },
        run,
        cancel,
    };
}

let uid = 0;

/**
 * Список-listbox внутри container и строка состояния под ним. Фокус остаётся в поле
 * поиска (role=combobox), активный пункт — aria-activedescendant.
 *
 * hrefFor(item) — пункт становится ссылкой (пилюля фильтра); без него — onChoose(item)
 * по mousedown (поле формы: до click список уже закрылся бы).
 */
export function createListbox(container, input, { onChoose = () => {}, hrefFor = null, grouped = false, icons = false, live = null } = {}) {
    uid += 1;
    const listId = `vf-pick-list-${uid}`;

    const list = document.createElement('div');
    list.className = 'vf-pick-list';
    list.id = listId;
    list.setAttribute('role', 'listbox');

    const state = document.createElement('div');
    state.className = 'vf-pick-state';
    state.hidden = true;

    const liveRegion = live ?? document.createElement('div');
    if (!live) {
        liveRegion.className = 'visually-hidden';
        container.appendChild(liveRegion);
    }
    liveRegion.setAttribute('aria-live', 'polite');

    container.prepend(list, state);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', listId);

    let options = [];
    let entries = [];
    let active = -1;

    const setActive = (index) => {
        active = index;
        options.forEach((option, i) => option.classList.toggle('active', i === active));

        if (active < 0) {
            input.removeAttribute('aria-activedescendant');

            return;
        }

        input.setAttribute('aria-activedescendant', options[active].id);
        options[active].scrollIntoView({ block: 'nearest' });
    };

    const choose = (index) => {
        const option = options[index];
        if (!option) {
            return;
        }
        if (hrefFor) {
            window.location.assign(option.href);

            return;
        }
        onChoose(entries[index]);
    };

    const buildOption = (item, index, query, selected) => {
        const option = document.createElement(hrefFor ? 'a' : 'div');
        option.className = 'vf-pick-option';
        option.id = `${listId}-${index}`;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', String(item.id === selected));
        if (hrefFor) {
            option.href = hrefFor(item);
            option.tabIndex = -1;
        } else {
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                onChoose(item);
            });
        }

        if (icons) {
            const icon = document.createElement('i');
            // Имя иконки задаёт адаптер (код), не данные.
            icon.className = `ti ti-${item.icon || 'circle-dashed'} vf-pick-option__icon`;
            icon.setAttribute('aria-hidden', 'true');
            option.appendChild(icon);
        }

        const label = document.createElement('span');
        label.className = 'vf-pick-option__label';
        appendHighlighted(label, item.label, query);
        option.appendChild(label);

        if (item.hint) {
            const hint = document.createElement('span');
            hint.className = 'vf-pick-option__hint';
            appendHighlighted(hint, item.hint, query);
            option.appendChild(hint);
        }

        return option;
    };

    const showState = (text, { busy = false, retry = null } = {}) => {
        state.replaceChildren();
        state.classList.toggle('vf-pick-state--busy', busy);
        const message = document.createElement('span');
        message.textContent = text;
        state.appendChild(message);

        if (retry) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary vf-pick-state__retry';
            button.textContent = 'Повторить';
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', retry);
            state.appendChild(button);
        }

        state.hidden = false;
    };

    return {
        id: listId,
        list,

        /** before — пункты над результатами без подсветки и групп («Все контрагенты»). */
        render(items, { query = '', selected = '', emptyText = 'Ничего не найдено', before = [] } = {}) {
            list.replaceChildren();
            options = [];
            entries = [];
            active = -1;
            input.removeAttribute('aria-activedescendant');

            const add = (item, parent, highlight) => {
                const option = buildOption(item, options.length, highlight, selected);
                options.push(option);
                entries.push(item);
                parent.appendChild(option);
            };

            before.forEach((item) => add(item, list, ''));

            if (grouped) {
                // Группы в порядке первого появления: верхний по релевантности остаётся сверху.
                const groups = new Map();
                items.forEach((item) => {
                    const key = item.group || '';
                    if (!groups.has(key)) {
                        groups.set(key, []);
                    }
                    groups.get(key).push(item);
                });

                groups.forEach((groupItems, title) => {
                    if (!title || groups.size < 2) {
                        groupItems.forEach((item) => add(item, list, query));

                        return;
                    }
                    const group = document.createElement('div');
                    group.setAttribute('role', 'group');
                    const header = document.createElement('div');
                    header.className = 'vf-pick-group';
                    header.id = `${listId}-g${list.children.length}`;
                    header.textContent = title;
                    group.setAttribute('aria-labelledby', header.id);
                    group.appendChild(header);
                    groupItems.forEach((item) => add(item, group, query));
                    list.appendChild(group);
                });
            } else {
                items.forEach((item) => add(item, list, query));
            }

            if (0 === items.length && '' !== query) {
                showState(emptyText);
                liveRegion.textContent = emptyText;
            } else {
                state.hidden = true;
                liveRegion.textContent = '' === query ? '' : `Найдено: ${items.length}`;
            }
        },

        loading() {
            list.replaceChildren();
            options = [];
            entries = [];
            setActive(-1);
            showState('Поиск…', { busy: true });
            liveRegion.textContent = 'Поиск…';
        },

        error(retry) {
            list.replaceChildren();
            options = [];
            entries = [];
            setActive(-1);
            showState('Не удалось загрузить список. Проверьте соединение.', { retry });
            liveRegion.textContent = 'Не удалось загрузить список';
        },

        clear() {
            list.replaceChildren();
            options = [];
            entries = [];
            setActive(-1);
            state.hidden = true;
        },

        /** ↑/↓ — по пунктам, Enter — выбрать активный. true — клавиша обработана. */
        handleKey(event) {
            if (0 === options.length) {
                return false;
            }

            if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
                event.preventDefault();
                setActive('ArrowDown' === event.key
                    ? Math.min(active + 1, options.length - 1)
                    : Math.max(active - 1, 0));

                return true;
            }

            if ('Enter' === event.key && active >= 0) {
                event.preventDefault();
                choose(active);

                return true;
            }

            return false;
        },
    };
}

/**
 * Портал: список в document.body с position: fixed под полем или над ним, если снизу
 * мало места. Внутри формы он попадал в стакинг-контекст блока условия и оказывался под
 * кнопками «Удалить» и «+ Добавить условие»; в модалке и оффканвасе дефект возвращался.
 */
export function positionPortal(portal, anchor) {
    const rect = anchor.getBoundingClientRect();
    const spaceBelow = window.innerHeight - rect.bottom - VIEWPORT_MARGIN;
    const spaceAbove = rect.top - VIEWPORT_MARGIN;
    const openUp = spaceBelow < MIN_PORTAL_HEIGHT && spaceAbove > spaceBelow;

    portal.style.left = `${rect.left}px`;
    portal.style.width = `${rect.width}px`;
    portal.style.maxHeight = `${Math.max(120, Math.min(320, openUp ? spaceAbove : spaceBelow))}px`;

    if (openUp) {
        portal.style.top = 'auto';
        portal.style.bottom = `${window.innerHeight - rect.top}px`;
    } else {
        portal.style.bottom = 'auto';
        portal.style.top = `${rect.bottom}px`;
    }
}

/** Инициализация по селектору сейчас и для узлов, добавленных позже (формы-коллекции). */
export function initEach(selector, init) {
    const scan = (root) => {
        if (root.matches?.(selector)) {
            init(root);
        }
        root.querySelectorAll?.(selector).forEach(init);
    };

    const start = () => {
        scan(document);
        new MutationObserver((mutations) => {
            mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
                if (1 === node.nodeType) {
                    scan(node);
                }
            }));
        }).observe(document.documentElement, { childList: true, subtree: true });
    };

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}
