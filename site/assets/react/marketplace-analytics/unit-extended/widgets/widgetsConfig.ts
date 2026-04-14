import type { WidgetCardConfig, WidgetsSummary } from './widgets.types';

/**
 * Считает сумму netAmount по выбранным widgetGroup.
 */
function getGroupNet(s: WidgetsSummary, ...names: string[]): number {
    return s.widgetGroups
        .filter((g) => names.includes(g.serviceGroup))
        .reduce((sum, g) => sum + g.netAmount, 0);
}

/**
 * Конфигурация 8 виджетов витрины MarketplaceAnalytics.
 */
export const WIDGETS: readonly WidgetCardConfig[] = [
    {
        key: 'revenue',
        label: 'Выручка',
        type: 'income',
        icon: 'ti ti-cash',
        getValue: (s) => s.revenue,
    },
    {
        key: 'returns',
        label: 'Возвраты',
        type: 'expense',
        icon: 'ti ti-arrow-back',
        getValue: (s) => s.returnsTotal,
    },
    {
        key: 'commission',
        label: 'Вознаграждение',
        type: 'expense',
        icon: 'ti ti-receipt',
        getValue: (s) => getGroupNet(s, 'Вознаграждение'),
    },
    {
        key: 'delivery_fbo',
        label: 'Услуги доставки и FBO',
        type: 'expense',
        icon: 'ti ti-truck-delivery',
        getValue: (s) => getGroupNet(s, 'Услуги доставки и FBO'),
    },
    {
        key: 'partners',
        label: 'Услуги партнёров',
        type: 'expense',
        icon: 'ti ti-users',
        getValue: (s) => getGroupNet(s, 'Услуги партнёров'),
    },
    {
        key: 'promo',
        label: 'Продвижение и реклама',
        type: 'expense',
        icon: 'ti ti-speakerphone',
        getValue: (s) => getGroupNet(s, 'Продвижение и реклама'),
    },
    {
        key: 'other',
        label: 'Другие услуги и штрафы',
        type: 'expense',
        icon: 'ti ti-alert-triangle',
        getValue: (s) => getGroupNet(s, 'Другие услуги и штрафы'),
    },
    {
        key: 'profit',
        label: 'Прибыль',
        type: 'profit',
        icon: 'ti ti-trending-up',
        getValue: (s) => s.profit,
    },
];

/**
 * Маппинг ключа виджета → список serviceGroup, которые раскрываются
 * в WidgetDetailPanel при клике.
 *
 * Виджеты revenue/returns/profit не раскрываются (нет групп).
 */
export const WIDGET_KEY_TO_GROUPS: Record<string, string[]> = {
    commission: ['Вознаграждение'],
    delivery_fbo: ['Услуги доставки и FBO'],
    partners: ['Услуги партнёров'],
    promo: ['Продвижение и реклама'],
    other: ['Другие услуги и штрафы'],
};
