/**
 * Поиск контрагента в форме (CounterpartyPickerType, тема form/counterparty_picker_theme.html.twig):
 * вид «поле» окна выбора поверх ядра picker_core.js. Разметка темы и data-cp-* не меняются.
 */
import { DEFAULT_MIN_QUERY, adapters, initEach } from './picker_core.js';
import { initPickerField } from './picker_field.js';

const optionName = (option) => option.dataset.name || option.textContent.trim();

function optionLabel(option) {
    const inn = option.dataset.inn || '';

    return inn ? `${optionName(option)} · ${inn}` : optionName(option);
}

function initOnePicker(root) {
    if ('1' === root.dataset.cpReady) {
        return;
    }
    root.dataset.cpReady = '1';

    const select = root.querySelector('[data-cp-select]');
    const searchWrap = root.querySelector('[data-cp-search-wrap]');
    const input = root.querySelector('[data-cp-input]');

    if (!select || !searchWrap || !input) {
        return;
    }

    initPickerField(root, {
        select,
        input,
        searchWrap,
        clearButton: root.querySelector('[data-cp-clear]'),
    }, {
        searchUrl: root.dataset.cpSearchUrl || '',
        minQuery: DEFAULT_MIN_QUERY,
        adapter: adapters.counterparty,
        rowFromOption: (option) => ({ id: option.value, name: optionName(option), inn: option.dataset.inn || null, type: null }),
        labelFromOption: optionLabel,
        texts: {
            invalid: 'Выберите контрагента из списка.',
            stale: 'Обновите страницу: контрагент появился после её открытия.',
        },
    });
}

// Формы-коллекции (операции документа, условия автоправила) добавляют строки динамически.
initEach('[data-cp-picker]', initOnePicker);
