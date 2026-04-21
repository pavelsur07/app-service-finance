export interface AdEfficiencyItem {
    listingId: string;
    sku: string;
    title: string | null;
    marketplace: string;
    revenue: string;
    adSpend: string;
    drrPercent: string | null;
}

export interface AdEfficiencyTotals {
    revenue: string;
    adSpend: string;
    drrPercent: string | null;
}

export interface AdEfficiencyResponse {
    items: AdEfficiencyItem[];
    total: number;
    page: number;
    pageSize: number;
    totals: AdEfficiencyTotals;
}

export interface MarketplaceOption {
    value: string;
    label: string;
}

export type SortBy = 'sku' | 'title' | 'revenue' | 'adSpend' | 'drrPercent';
export type SortDir = 'asc' | 'desc';
