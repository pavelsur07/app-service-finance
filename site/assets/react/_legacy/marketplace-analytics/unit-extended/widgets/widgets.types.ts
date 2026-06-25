import type { CostGroupBreakdown } from '../unitExtended.types';

export interface WidgetsSummary {
    revenue: number;
    returnsTotal: number;
    costPriceTotal: number;
    totalCosts: number;
    profit: number;
    marginPercent: number | null;
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
}
