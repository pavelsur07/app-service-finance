/**
 * Вид «пилюля фильтра» окна выбора (templates/partials/_filter_pill_picker.html.twig):
 * кнопка → поповер (dropdown Bootstrap: Esc и клик снаружи закрывают, фокус возвращается
 * на пилюлю, Popper держит окно в экране). Выбор — ссылка на ту же страницу с параметром
 * фильтра и прочими текущими параметрами без page.
 *
 * До ввода — список из data-picker-initial (сервер отдаёт [] при q короче минимума,
 * поэтому первые строки приходят со страницей, без запроса).
 */
import { createListbox, createSearch, initEach, readConfig } from './picker_core.js';

function initOnePill(root) {
    if ('1' === root.dataset.pickerReady) {
        return;
    }
    root.dataset.pickerReady = '1';

    const toggle = root.querySelector('[data-bs-toggle="dropdown"]');
    const input = root.querySelector('[data-picker-input]');
    const results = root.querySelector('[data-picker-results]');
    const hint = root.querySelector('[data-picker-hint]');

    if (!toggle || !input || !results) {
        return;
    }

    const config = readConfig(root);
    if (!config.param || !config.searchUrl) {
        console.error('picker pill: data-picker-param and data-picker-search-url are required', root);

        return;
    }
    const base = new URL(root.dataset.pickerBaseUrl || window.location.pathname, window.location.origin);
    const hrefFor = (item) => {
        const url = new URL(base);
        if (item.id) {
            url.searchParams.set(config.param, item.id);
        }

        return `${url.pathname}${url.search}`;
    };

    let initial = [];
    try {
        initial = JSON.parse(root.dataset.pickerInitial || '[]').map(config.adapter);
    } catch {
        initial = [];
    }
    const reset = { id: '', label: root.dataset.pickerAllLabel || 'Все' };

    const listbox = createListbox(results, input, { hrefFor, grouped: true, icons: true });

    const showInitial = () => {
        if (hint) {
            hint.hidden = false;
        }
        listbox.render(initial, { selected: config.value, before: [reset] });
    };

    const search = createSearch({
        url: config.searchUrl,
        adapter: config.adapter,
        onLoading: () => listbox.loading(),
        onResults: (items, query) => listbox.render(items, { query, selected: config.value, emptyText: config.emptyText }),
        onError: (query) => listbox.error(() => search.run(query)),
    });

    input.addEventListener('input', () => {
        const query = input.value.trim();

        if (query.length < config.minQuery) {
            search.cancel();
            showInitial();

            return;
        }

        if (hint) {
            hint.hidden = true;
        }
        search.schedule(query);
    });

    // ↑/↓/Enter — ядро; Esc обрабатывает dropdown Bootstrap (закрыть, фокус на пилюлю).
    input.addEventListener('keydown', (event) => listbox.handleKey(event));

    toggle.addEventListener('shown.bs.dropdown', () => input.focus());
    toggle.addEventListener('hidden.bs.dropdown', () => {
        search.cancel();
        input.value = '';
        showInitial();
    });

    showInitial();
}

initEach('[data-picker-pill]', initOnePill);
