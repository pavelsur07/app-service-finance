/**
 * DTO типы для KPI метрик аналитики маркетплейса
 */

export type KpiMetrics = {
    revenue: string; // decimal строка
    margin: string; // decimal строка
    units_sold: number;
    roi: number; // процент
    return_rate: number; // процент
    turnover_days: number; // дни
    currency: string; // ISO код валюты
};

export type KpiPreviousMetrics = {
    revenue: string;
    margin: string;
    units_sold: number;
};

export type KpiResponse = {
    current: KpiMetrics;
    previous: KpiPreviousMetrics;
};

/**
 * Параметры запроса KPI
 */
export type KpiQueryParams = {
    from: string; // YYYY-MM-DD
    to: string; // YYYY-MM-DD
    marketplace?: string; // "all" | "wildberries" | "ozon"
};

/**
 * Расчет изменения относительно предыдущего периода
 */
export type KpiGrowth = {
    absolute: number; // абсолютное изменение
    percent: number; // процентное изменение
    isPositive: boolean; // рост или падение
};
