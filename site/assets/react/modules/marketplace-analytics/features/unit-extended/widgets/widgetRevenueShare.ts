import type { WidgetCardConfig, WidgetsSummary } from './widgets.types';

const percent = new Intl.NumberFormat('ru-RU', { style: 'percent', maximumFractionDigits: 1 });
const negativeZero = percent.format(-0);
const zero = percent.format(0);

export function formatWidgetRevenueShare(
    config: WidgetCardConfig,
    value: number,
    current: WidgetsSummary,
): string {
    // Возвраты уже приходят в P&L-знаке.
    const revenueBase = config.key === 'returns'
        ? current.revenue
        : current.revenue + current.returnsTotal;
    if (revenueBase === 0) {
        return '—';
    }

    // Расход увеличивает долю затрат, компенсация уменьшает её.
    const amount = config.type === 'expense' ? -value : value;
    const share = percent.format(amount / revenueBase);
    return share === negativeZero ? zero : share;
}
