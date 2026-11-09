import { formatMoney } from '@/react/shared/format/number';
import type { WidgetCardConfig, WidgetsSummary } from './widgets.types';

/**
 * Группа «Прочие» на бэке (WidgetSummaryQuery) — колонка «Прочие» таблицы.
 * Внутри неё WidgetDetailPanel отдельно показывает подгруппу компенсаций.
 */
export const OTHER_SERVICES_GROUP = 'Прочие';

/**
 * Считает сумму netAmount по выбранным widgetGroup.
 */
function getGroupNet(s: WidgetsSummary, ...names: string[]): number {
    return s.widgetGroups
        .filter((g) => names.includes(g.serviceGroup))
        .reduce((sum, g) => sum + g.netAmount, 0);
}

/**
 * 8 карточек — величины строки «Итого» таблицы под ними (в P&L-знаке: расход < 0).
 * Реклама — по статистике рекламного кабинета, как в таблице; начисления маркетплейса
 * по рекламе — справкой под карточкой (#2940, этап 3).
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
        key: 'cost_price',
        label: 'Себестоимость',
        type: 'expense',
        icon: 'ti ti-package',
        getValue: (s) => s.costPriceTotal,
    },
    {
        key: 'commission',
        label: 'Комиссия',
        type: 'expense',
        icon: 'ti ti-receipt',
        getValue: (s) => getGroupNet(s, 'Комиссия'),
    },
    {
        key: 'logistics',
        label: 'Логистика',
        type: 'expense',
        icon: 'ti ti-truck-delivery',
        getValue: (s) => getGroupNet(s, 'Логистика'),
    },
    {
        key: 'ads',
        label: 'Реклама',
        type: 'expense',
        icon: 'ti ti-speakerphone',
        getValue: (s) => getGroupNet(s, 'Реклама'),
        getNote: (s) => (s.adAccrued === null
            ? null
            : `по начислениям маркетплейса: ${formatMoney(s.adAccrued)}`),
    },
    {
        key: 'other',
        label: 'Прочие',
        type: 'expense',
        icon: 'ti ti-alert-triangle',
        getValue: (s) => getGroupNet(s, OTHER_SERVICES_GROUP),
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
 * Выручка, возвраты, себестоимость, реклама (статистика без категорий) и прибыль
 * не раскрываются.
 */
export const WIDGET_KEY_TO_GROUPS: Record<string, string[]> = {
    commission: ['Комиссия'],
    logistics: ['Логистика'],
    // Компенсации и декомпенсации входят в «Прочие»; в WidgetDetailPanel они
    // визуально выделяются в подгруппу через COMPENSATION_CODES.
    other: [OTHER_SERVICES_GROUP],
};

/**
 * Коды категорий, относящиеся к компенсациям и декомпенсациям.
 *
 * Используется в WidgetDetailPanel для визуального выделения
 * подгруппы «Компенсации и декомпенсации» внутри группы
 * «Прочие».
 */
export const COMPENSATION_CODES: readonly string[] = [
    'ozon_compensation',
    'ozon_decompensation',
];
