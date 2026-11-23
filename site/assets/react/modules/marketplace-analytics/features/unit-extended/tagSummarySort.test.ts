import { describe, expect, it } from 'vitest';
import { sortTagSummary } from './tagSummarySort';
import type { TagSummarySortDirection, TagSummarySortField } from './tagSummarySort';
import type { TagSummaryRow } from './unitExtended.types';

function row(tagId: string | null, overrides: Partial<TagSummaryRow> = {}): TagSummaryRow {
    return {
        tagId,
        name: tagId ?? 'Без тегов',
        listingsCount: 0,
        revenue: 0,
        quantity: 0,
        averageCheckRub: 0,
        returnsTotal: 0,
        returnsQuantity: 0,
        costPriceTotal: 0,
        stockCapitalRub: 0,
        stockCapitalUnknownCount: 0,
        commission: 0,
        commissionAverageRub: 0,
        adSpend: 0,
        cacRub: 0,
        drrPercent: 0,
        logistics: 0,
        otherCosts: 0,
        totalCosts: 0,
        profit: 0,
        marginPercent: 0,
        roiPercent: 0,
        ...overrides,
    };
}

const numericFields = [
    'listingsCount', 'revenue', 'quantity', 'averageCheckRub', 'costPriceTotal',
    'stockCapitalRub', 'adSpend', 'totalCosts', 'profit', 'marginPercent',
] as const satisfies readonly TagSummarySortField[];
const fields: TagSummarySortField[] = ['name', ...numericFields];
const directions: TagSummarySortDirection[] = ['asc', 'desc'];

describe('sortTagSummary', () => {
    it.each(numericFields)('sorts raw %s values in both directions, including zero and negatives', (field) => {
        const rows = [
            row('zero', { [field]: 0 }),
            row('high', { [field]: 1200.004 }),
            row('negative', { [field]: -20 }),
            row('low', { [field]: 1200.003 }),
        ];

        expect(sortTagSummary(rows, field, 'asc').map((value) => value.tagId))
            .toEqual(['negative', 'zero', 'low', 'high']);
        expect(sortTagSummary(rows, field, 'desc').map((value) => value.tagId))
            .toEqual(['high', 'low', 'zero', 'negative']);
    });

    it('sorts names using Russian collation in both directions', () => {
        const rows = [row('я', { name: 'Яблоки' }), row('а', { name: 'Арбузы' }), row('ё', { name: 'Ёлки' }), row('е', { name: 'Еда' })];

        expect(sortTagSummary(rows, 'name', 'asc').map((value) => value.name))
            .toEqual(['Арбузы', 'Еда', 'Ёлки', 'Яблоки']);
        expect(sortTagSummary(rows, 'name', 'desc').map((value) => value.name))
            .toEqual(['Яблоки', 'Ёлки', 'Еда', 'Арбузы']);
    });

    it.each(['averageCheckRub', 'stockCapitalRub', 'marginPercent'] as const)('places null %s before numbers ascending and after descending', (field) => {
        const rows = [row('zero'), row('null-first', { [field]: null }), row('negative', { [field]: -1 }), row('null-second', { [field]: null })];

        expect(sortTagSummary(rows, field, 'asc').map((value) => value.tagId))
            .toEqual(['null-first', 'null-second', 'negative', 'zero']);
        expect(sortTagSummary(rows, field, 'desc').map((value) => value.tagId))
            .toEqual(['zero', 'negative', 'null-first', 'null-second']);
    });

    describe.each(directions)('%s', (direction) => {
        it.each(fields)('preserves API order for equal %s values without mutating input', (field) => {
            const first = Object.freeze(row('first', { name: 'Одинаковые' }));
            const second = Object.freeze(row('second', { name: 'Одинаковые' }));
            const rows = Object.freeze([first, second]);
            const sorted = sortTagSummary(rows, field, direction);

            expect(sorted).toEqual([first, second]);
            expect(sorted).not.toBe(rows);
            expect(sorted[0]).toBe(first);
            expect(sorted[1]).toBe(second);
            expect(rows).toEqual([first, second]);
        });

        it.each(fields)('pins the untagged row last for %s, identified by tagId', (field) => {
            const low = row('low', { name: 'А' });
            const high = row('high', { name: 'Я', ...Object.fromEntries(numericFields.map((key) => [key, 10])) });
            const untagged = row(null, { name: 'Другое имя', ...Object.fromEntries(numericFields.map((key) => [key, 5])) });
            const namedTag = row('named', { name: 'Без тегов', ...Object.fromEntries(numericFields.map((key) => [key, 3])) });
            const rows = Object.freeze([untagged, high, namedTag, low]);

            expect(sortTagSummary(rows, field, direction))
                .toEqual(direction === 'asc' ? [low, namedTag, high, untagged] : [high, namedTag, low, untagged]);
            expect(rows).toEqual([untagged, high, namedTag, low]);
        });

        it('handles empty and single-row arrays', () => {
            expect(sortTagSummary([], 'revenue', direction)).toEqual([]);
            const untagged = row(null);
            expect(sortTagSummary([untagged], 'revenue', direction)).toEqual([untagged]);
        });
    });
});
