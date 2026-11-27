import { describe, expect, it } from 'vitest';
import { formatWidgetRevenueShare } from './widgetRevenueShare';
import { WIDGETS } from './widgetsConfig';
import type { WidgetsSummary } from './widgets.types';

const summary: WidgetsSummary = {
    revenue: 100_000,
    returnsTotal: -20_000,
    costPriceTotal: -30_000,
    totalCosts: -15_000,
    profit: 35_000,
    marginPercent: 35,
    adAccrued: null,
    widgetGroups: [
        { serviceGroup: 'Комиссия', costsAmount: -5_000, stornoAmount: 0, netAmount: -5_000, categories: [] },
        { serviceGroup: 'Логистика', costsAmount: -3_000, stornoAmount: 0, netAmount: -3_000, categories: [] },
        { serviceGroup: 'Реклама', costsAmount: -8_000, stornoAmount: 0, netAmount: -8_000, categories: [] },
        { serviceGroup: 'Прочие', costsAmount: 0, stornoAmount: 1_000, netAmount: 1_000, categories: [] },
    ],
};

function shares(current: WidgetsSummary): Record<string, string> {
    return Object.fromEntries(WIDGETS.map((config) => [
        config.key,
        formatWidgetRevenueShare(config, config.getValue(current), current),
    ]));
}

describe('доли от выручки в карточках', () => {
    it('считает возвраты от полной выручки, а остальные показатели от нетто-выручки', () => {
        expect(shares(summary)).toEqual({
            revenue: '125\u00a0%',
            returns: '20\u00a0%',
            cost_price: '37,5\u00a0%',
            commission: '6,3\u00a0%',
            logistics: '3,8\u00a0%',
            ads: '10\u00a0%',
            other: '-1,3\u00a0%',
            profit: '43,8\u00a0%',
        });
    });

    it('показывает долю возвратов при нулевой нетто-выручке', () => {
        expect(shares({ ...summary, revenue: 20_000 })).toEqual({
            revenue: '—',
            returns: '100\u00a0%',
            cost_price: '—',
            commission: '—',
            logistics: '—',
            ads: '—',
            other: '—',
            profit: '—',
        });
    });

    it('показывает прочерк у возвратов при нулевой полной выручке', () => {
        expect(shares({ ...summary, revenue: 0 }).returns).toBe('—');
    });

    it('показывает нулевую долю, когда при полной выручке возвратов нет', () => {
        expect(shares({ ...summary, returnsTotal: 0 }).returns).toBe('0\u00a0%');
    });

    it('сохраняет знак убытка и форматирует нулевой расход без минуса', () => {
        expect(shares({ ...summary, profit: -8_000, costPriceTotal: 0 })).toMatchObject({
            cost_price: '0\u00a0%',
            profit: '-10\u00a0%',
        });
    });

    it('убирает минус у доли, которая округляется до нуля', () => {
        expect(shares({ ...summary, profit: -8 }).profit).toBe('0\u00a0%');
    });
});
