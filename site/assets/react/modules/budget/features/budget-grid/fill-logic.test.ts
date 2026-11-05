import { describe, expect, it } from 'vitest';
import type { BudgetFillResult, BudgetGridRow } from './budget-grid.types';
import {
    fillGroups,
    fillTargets,
    filterGroups,
    freeLeafIds,
    groupState,
    helperParams,
    plural,
    previewLine,
    previewTable,
    quarterChips,
    setSelected,
    targetsLine,
    usesMonthPicker,
} from './fill-logic';

const row = (id: string, level: number, editable = true, name = id): BudgetGridRow => ({
    id,
    code: null,
    name,
    level,
    type: editable ? 'LEAF_INPUT' : 'SUBTOTAL',
    flow: 'expense',
    format: 'MONEY',
    editable,
    values: {},
    total: '0.00',
    comments: {},
    rule: null,
});

const ruled = (base: BudgetGridRow): BudgetGridRow => ({ ...base, rule: { percent: '5.00', baseCategoryId: 'wb', baseName: 'WB' } });

// Выручка ▾ (WB, Ozon), Расходы ▾ (Аренда, Склад ▾ (Хранение)), Курсовые (лист без группы), Прибыль.
const ROWS = [
    row('rev', 1, false, 'Выручка'), row('wb', 2, true, 'Выручка WB'), row('ozon', 2, true, 'Выручка Ozon'),
    row('exp', 1, false, 'Расходы'), row('rent', 2, true, 'Аренда'), row('stock', 2, false, 'Склад'), row('keep', 3, true, 'Хранение'),
    row('fx', 1, true, 'Курсовые разницы'),
    row('profit', 1, false, 'Прибыль'),
];
const MONTHS = Array.from({ length: 12 }, (_, i) => `2027-${String(i + 1).padStart(2, '0')}`);

describe('fillGroups', () => {
    it('группирует статьи по ближайшему родителю, без родителя — «Прочее» в конце', () => {
        const groups = fillGroups(ROWS);
        expect(groups.map((g) => [g.name, g.leaves.map((r) => r.id)])).toEqual([
            ['Выручка', ['wb', 'ozon']],
            ['Расходы', ['rent']],
            ['Склад', ['keep']],
            ['Прочее', ['fx']],
        ]);
    });

    it('без листьев вне групп «Прочее» нет', () => {
        expect(fillGroups(ROWS.slice(0, 3)).map((g) => g.name)).toEqual(['Выручка']);
    });
});

describe('filterGroups', () => {
    it('ищет по названию статьи без учёта регистра и прячет пустые группы', () => {
        const found = filterGroups(fillGroups(ROWS), '  выручка O');
        expect(found.map((g) => [g.name, g.leaves.map((r) => r.id)])).toEqual([['Выручка', ['ozon']]]);
    });

    it('пустой запрос — все группы', () => {
        expect(filterGroups(fillGroups(ROWS), ' ')).toHaveLength(4);
    });
});

describe('groupState и выбор', () => {
    const [revenue] = fillGroups(ROWS);

    it('off / mixed / on по доступным статьям', () => {
        expect(groupState(revenue, new Set())).toEqual({ state: 'off', selected: 0, total: 2 });
        expect(groupState(revenue, new Set(['wb']))).toEqual({ state: 'mixed', selected: 1, total: 2 });
        expect(groupState(revenue, new Set(['wb', 'ozon']))).toEqual({ state: 'on', selected: 2, total: 2 });
    });

    it('статья с правилом не считается и не выбирается', () => {
        const groups = fillGroups([ROWS[0], ruled(ROWS[1]), ROWS[2]]);
        expect(groupState(groups[0], new Set(['ozon']))).toEqual({ state: 'on', selected: 1, total: 1 });
        expect(freeLeafIds(groups)).toEqual(['ozon']);
    });

    it('setSelected включает и выключает набор, не трогая остальное', () => {
        const on = setSelected(new Set(['fx']), ['wb', 'ozon'], true);
        expect([...on].sort()).toEqual(['fx', 'ozon', 'wb']);
        expect([...setSelected(on, ['wb', 'ozon'], false)]).toEqual(['fx']);
    });
});

describe('quarterChips', () => {
    it('квартал включён, только если выбраны все его месяцы', () => {
        const chips = quarterChips(MONTHS, new Set(['2027-01', '2027-02', '2027-03', '2027-04']));
        expect(chips.map((c) => [c.label, c.on])).toEqual([['1 кв.', true], ['2 кв.', false], ['3 кв.', false], ['4 кв.', false]]);
        expect(chips[1].months).toEqual(['2027-04', '2027-05', '2027-06']);
    });
});

describe('fillTargets и targetsLine', () => {
    const selected = new Set(['wb', 'rent', 'stock', 'unknown']);

    it('выбранные доступные статьи в порядке дерева × выбранные месяцы', () => {
        const targets = fillTargets('last_year_fact', ROWS, selected, MONTHS, new Set(['2027-02', '2027-01']), MONTHS[0]);
        expect(targets).toEqual({ categoryIds: ['wb', 'rent'], months: ['2027-01', '2027-02'] });
        expect(targetsLine(targets, true)).toBe('Будет затронуто 4 ячейки: 2 статьи × 2 месяца');
    });

    it('распределение — все месяцы, копирование — правее исходного', () => {
        expect(fillTargets('distribute', ROWS, selected, MONTHS, new Set(), MONTHS[0]).months).toHaveLength(12);
        expect(fillTargets('copy_right', ROWS, selected, MONTHS, new Set(), '2027-10').months).toEqual(['2027-11', '2027-12']);
        expect(usesMonthPicker('distribute')).toBe(false);
        expect(usesMonthPicker('average')).toBe(true);
    });

    it('статья с правилом не попадает в цели', () => {
        const rows = ROWS.map((r) => (r.id === 'wb' ? ruled(r) : r));
        expect(fillTargets('average', rows, selected, MONTHS, new Set(MONTHS), MONTHS[0]).categoryIds).toEqual(['rent']);
    });

    it('подсказки, когда затрагивать нечего', () => {
        expect(targetsLine({ categoryIds: [], months: MONTHS }, true)).toBe('Выберите хотя бы одну статью и месяц.');
        expect(targetsLine({ categoryIds: [], months: MONTHS }, false)).toBe('Выберите хотя бы одну статью.');
        expect(targetsLine({ categoryIds: ['wb'], months: [] }, false)).toBe('Нет месяцев правее исходного.');
        expect(targetsLine({ categoryIds: ['wb'], months: ['2027-01'] }, true)).toBe('Будет затронуто 1 ячейка: 1 статья × 1 месяц');
    });
});

describe('helperParams', () => {
    const inputs = { average: 6, amount: '', sourceMonth: '2027-03', percent: '', baseId: 'wb' };

    it('среднее, копирование и факт — без проверки полей', () => {
        expect(helperParams('average', inputs)).toEqual({ ok: true, params: { months: 6 } });
        expect(helperParams('copy_right', inputs)).toEqual({ ok: true, params: { sourceMonth: '2027-03' } });
        expect(helperParams('last_year_fact', inputs)).toEqual({ ok: true, params: {} });
    });

    it('годовая сумма: пусто — без ошибки, мусор и ноль — ошибка, число — сумма', () => {
        expect(helperParams('distribute', inputs)).toEqual({ ok: false, errors: {} });
        expect(helperParams('distribute', { ...inputs, amount: 'abc' })).toEqual({ ok: false, errors: { amount: 'Введите годовую сумму, например 1 200 000.' } });
        expect(helperParams('distribute', { ...inputs, amount: '0' }).ok).toBe(false);
        expect(helperParams('distribute', { ...inputs, amount: '1 200 000' })).toEqual({ ok: true, params: { amount: '1200000.00' } });
    });

    it('процент: пусто, отрицательный, нет базы, верный', () => {
        expect(helperParams('percent_of', inputs)).toEqual({ ok: false, errors: {} });
        expect(helperParams('percent_of', { ...inputs, percent: '-5' })).toEqual({ ok: false, errors: { percent: 'Введите процент, например 5 или 7,5.' } });
        expect(helperParams('percent_of', { ...inputs, percent: '5', baseId: '' })).toEqual({ ok: false, errors: { baseId: 'Нет статьи-базы: в дереве нет денежных строк.' } });
        expect(helperParams('percent_of', { ...inputs, percent: '7,5' })).toEqual({ ok: true, params: { percent: '7.50', baseCategoryId: 'wb' } });
    });
});

describe('previewTable', () => {
    const rows = ROWS.map((r) => (r.id === 'rent' ? { ...r, values: { '2027-02': '-50.00' } } : r));
    const result: BudgetFillResult = {
        cells: [
            { plCategoryId: 'rent', month: '2027-03', amount: '-120.00', previous: '-200.00' },
            { plCategoryId: 'wb', month: '2027-01', amount: '100.50', previous: '0.00' },
            { plCategoryId: 'rent', month: '2027-01', amount: '-100.00', previous: '0.00' },
        ],
        changed: 3,
        overwrite: 1,
        skipped: 0,
        applied: false,
    };

    it('строки и месяцы с изменениями в порядке сетки, неизменённые — с текущим значением', () => {
        const table = previewTable(result, rows, MONTHS);
        expect(table.months).toEqual(['2027-01', '2027-03']);
        expect(table.rows.map((r) => r.id)).toEqual(['wb', 'rent']);
        expect(table.rows[0].cells[1]).toEqual({ month: '2027-03', amount: null, previous: '0.00', overwrite: false });
        expect(table.rows[1].cells).toEqual([
            { month: '2027-01', amount: '-100.00', previous: '0.00', overwrite: false },
            { month: '2027-03', amount: '-120.00', previous: '-200.00', overwrite: true },
        ]);
    });

    it('«Изменение» — сумма (новое − было) по статье', () => {
        const table = previewTable(result, rows, MONTHS);
        expect(table.rows.map((r) => r.delta)).toEqual(['100.50', '-20.00']);
    });

    it('строка итога', () => {
        expect(previewLine(result)).toBe('Изменится 3 ячейки, из них перезапишет заполненные: 1');
        expect(previewLine({ ...result, overwrite: 0, changed: 21 })).toBe('Изменится 21 ячейка');
        expect(previewLine({ ...result, changed: 0 })).toBe('Менять нечего: суммы уже такие.');
    });
});

describe('plural', () => {
    it('1, 2–4, 5–20, 11–14', () => {
        expect([1, 3, 5, 11, 12, 21, 22, 25, 111].map((n) => plural(n, 'a', 'b', 'c')).join('')).toBe('abccc' + 'abcc');
    });
});
