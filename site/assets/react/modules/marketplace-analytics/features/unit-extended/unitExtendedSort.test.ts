import { describe, expect, it } from 'vitest';
import { sortUnitExtendedItems } from './unitExtendedSort';
import type { UnitExtendedItem } from './unitExtended.types';

function listing(listingId: string, overrides: Partial<UnitExtendedItem> = {}): UnitExtendedItem {
    return {
        listingId,
        sku: listingId,
        title: '',
        sellerArticle: '',
        marketplace: 'ozon',
        revenue: 0,
        quantity: 0,
        averageCheckRub: null,
        returnsTotal: 0,
        returnsQuantity: 0,
        costPriceTotal: 0,
        costPriceUnit: null,
        stockQty: null,
        stockCapitalRub: null,
        commission: 0,
        commissionAverageRub: null,
        adSpend: 0,
        cacRub: null,
        drrPercent: null,
        logistics: 0,
        otherCosts: 0,
        totalCosts: 0,
        profit: 0,
        profitUnit: null,
        marginPercent: null,
        roiPercent: null,
        otherCostsBreakdown: [],
        allCostsBreakdown: [],
        tags: [],
        abcRevenue: 'C',
        abcProfit: 'D',
        ...overrides,
    };
}

describe('sortUnitExtendedItems', () => {
    it('сортирует числовые показатели, сохраняя порядок равных строк и исходный массив', () => {
        const rows = [listing('a', { profit: -2 }), listing('b', { profit: 10 }), listing('c', { profit: 10 })];

        expect(sortUnitExtendedItems(rows, 'profit', 'desc').map((row) => row.listingId)).toEqual(['b', 'c', 'a']);
        expect(sortUnitExtendedItems(rows, 'profit', 'asc').map((row) => row.listingId)).toEqual(['a', 'b', 'c']);
        expect(rows.map((row) => row.listingId)).toEqual(['a', 'b', 'c']);
    });

    it('отличает неизвестный показатель от нуля и сохраняет порядок неизвестных значений', () => {
        const rows = [listing('a'), listing('b', { stockQty: 0 }), listing('c', { stockQty: 4 }), listing('d')];

        expect(sortUnitExtendedItems(rows, 'stockQty', 'desc').map((row) => row.listingId)).toEqual(['c', 'b', 'a', 'd']);
        expect(sortUnitExtendedItems(rows, 'stockQty', 'asc').map((row) => row.listingId)).toEqual(['a', 'd', 'b', 'c']);
    });

    it('начинает сортировку ABC с лучших классов', () => {
        const rows = [listing('d'), listing('a', { abcProfit: 'A' }), listing('c', { abcProfit: 'C' }), listing('b', { abcProfit: 'B' })];

        expect(sortUnitExtendedItems(rows, 'abcProfit', 'desc').map((row) => row.listingId)).toEqual(['a', 'b', 'c', 'd']);
        expect(sortUnitExtendedItems(rows, 'abcProfit', 'asc').map((row) => row.listingId)).toEqual(['d', 'c', 'b', 'a']);
    });

    it('сортирует названия и теги по русской локали, учитывая порядок тегов из API', () => {
        const alpha = { id: '1', name: 'Альфа' };
        const beta = { id: '2', name: 'Бета' };
        const rows = [
            listing('a', { title: 'Бета', tags: [beta, alpha] }),
            listing('b', { title: 'Альфа', tags: [alpha, beta] }),
            listing('c'),
        ];

        expect(sortUnitExtendedItems(rows, 'title', 'asc').map((row) => row.listingId)).toEqual(['c', 'b', 'a']);
        expect(sortUnitExtendedItems(rows, 'tags', 'asc').map((row) => row.listingId)).toEqual(['c', 'b', 'a']);
    });
});
