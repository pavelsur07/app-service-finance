import { describe, expect, it } from 'vitest';
import type { BudgetGridRow } from './budget-grid.types';
import { planPaste, visibleRows } from './grid-logic';

const row = (id: string, level: number, flow: BudgetGridRow['flow'] = 'income', editable = true): BudgetGridRow => ({
    id,
    code: null,
    name: id,
    level,
    type: editable ? 'LEAF_INPUT' : 'SUBTOTAL',
    flow,
    format: 'MONEY',
    editable,
    values: {},
    total: '0.00',
    comments: {},
    rule: null,
});

// Выручка ▾ (WB, Ozon), Расходы ▾ (Аренда ▾ (Склад)), Прибыль.
const ROWS = [
    row('rev', 1, 'income', false), row('wb', 2), row('ozon', 2),
    row('exp', 1, 'expense', false), row('rent', 2, 'expense', false), row('stock', 3, 'expense'),
    row('profit', 1, 'none', false),
];
const MONTHS = ['2027-01', '2027-02', '2027-03'];

describe('visibleRows', () => {
    it('без свёртки — все строки, дочерние отмечены у групп', () => {
        const result = visibleRows(ROWS, new Set());
        expect(result.map((r) => r.row.id)).toEqual(ROWS.map((r) => r.id));
        expect(result.filter((r) => r.hasChildren).map((r) => r.row.id)).toEqual(['rev', 'exp', 'rent']);
    });

    it('свёрнутая группа прячет всё поддерево до строки своего уровня', () => {
        const result = visibleRows(ROWS, new Set(['exp']));
        expect(result.map((r) => r.row.id)).toEqual(['rev', 'wb', 'ozon', 'exp', 'profit']);
        expect(result.find((r) => r.row.id === 'exp')?.collapsed).toBe(true);
    });

    it('свёртка листа без дочерних ничего не прячет', () => {
        expect(visibleRows(ROWS, new Set(['wb'])).length).toBe(ROWS.length);
    });
});

describe('planPaste', () => {
    const rows = [ROWS[1], ROWS[2], ROWS[3], ROWS[5]];
    const editable = (p: { row: number }): boolean => rows[p.row]?.editable ?? false;

    it('прямоугольник с текущей ячейки, разные записи чисел', () => {
        const plan = planPaste('1 000\t2000,5\r\n3\t4\r\n', { row: 0, col: 0 }, rows, MONTHS, editable);
        expect(plan.skipped).toBe(0);
        expect(plan.cells.map((c) => [c.row.id, c.month, c.amount])).toEqual([
            ['wb', '2027-01', '1000.00'], ['wb', '2027-02', '2000.50'],
            ['ozon', '2027-01', '3.00'], ['ozon', '2027-02', '4.00'],
        ]);
    });

    it('нередактируемые, нечисловые и пустые — пропуск со счётом; пустая не очищает', () => {
        const plan = planPaste('x\t\t5\n1\t2\t3', { row: 1, col: 0 }, rows, MONTHS, editable);
        expect(plan.cells.map((c) => [c.row.id, c.month])).toEqual([['ozon', '2027-03']]);
        expect(plan.skipped).toBe(5);
    });

    it('за декабрём и за последней строкой — отбрасывается без счёта', () => {
        const plan = planPaste('1\t2\t3\n4\n5', { row: 3, col: 1 }, rows, MONTHS, editable);
        expect(plan.cells.map((c) => [c.row.id, c.month, c.amount])).toEqual([['stock', '2027-02', '-1.00'], ['stock', '2027-03', '-2.00']]);
        expect(plan.skipped).toBe(0);
    });

    it('расход — с минусом, ввод с минусом в расходе — доход', () => {
        const plan = planPaste('-7', { row: 3, col: 0 }, rows, MONTHS, editable);
        expect(plan.cells[0]?.amount).toBe('7.00');
    });

    it('пустой буфер — ничего', () => {
        expect(planPaste('', { row: 0, col: 0 }, rows, MONTHS, editable)).toEqual({ cells: [], skipped: 0 });
    });
});
