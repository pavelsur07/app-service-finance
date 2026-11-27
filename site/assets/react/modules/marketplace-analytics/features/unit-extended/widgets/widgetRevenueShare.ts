import type { WidgetCardConfig, WidgetsSummary } from './widgets.types';

const percent = new Intl.NumberFormat('ru-RU', { style: 'percent', maximumFractionDigits: 1 });
const negativeZero = percent.format(-0);
const zero = percent.format(0);

export function formatWidgetRevenueShare(
    config: WidgetCardConfig,
    value: number,
    current: WidgetsSummary,
): string | null {
    if (config.key === 'returns') {
        return null;
    }

    // Возвраты уже приходят в P&L-знаке.
    const netRevenue = current.revenue + current.returnsTotal;
    if (netRevenue === 0) {
        return '—';
    }

    // Расход увеличивает долю затрат, компенсация уменьшает её.
    const amount = config.type === 'expense' ? -value : value;
    const share = percent.format(amount / netRevenue);
    return share === negativeZero ? zero : share;
}
