import type { CostGroupBreakdown } from '../unitExtended.types';

export interface WidgetsSummary {
    revenue: number;
    returnsTotal: number;
    costPriceTotal: number;
    totalCosts: number;
    profit: number;
    marginPercent: number | null;
    /** Реклама по начислениям маркетплейса (P&L-знак) — справка; null под фильтром тегов. */
    adAccrued: number | null;
    widgetGroups: CostGroupBreakdown[];
}

export interface WidgetsApiResponse {
    current: WidgetsSummary;
    previous: WidgetsSummary;
    period: {
        from: string;
        to: string;
        previousFrom: string;
        previousTo: string;
    };
}

export type WidgetType = 'income' | 'expense' | 'profit';

export interface WidgetCardConfig {
    key: string;
    label: string;
    type: WidgetType;
    icon: string;
    getValue: (s: WidgetsSummary) => number;
    /** Строка-справка под суммой карточки; null — не показывать. */
    getNote?: (s: WidgetsSummary) => string | null;
}
