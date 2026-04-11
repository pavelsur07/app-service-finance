export interface CostCategory {
    code: string;
    name: string;
    costsAmount: number;
    stornoAmount: number;
    netAmount: number;
}

export interface CostGroupBreakdown {
    serviceGroup: string;
    costsAmount: number;
    stornoAmount: number;
    netAmount: number;
    categories: CostCategory[];
}

export interface UnitExtendedItem {
    listingId: string;
    title: string;
    sku: string;
    marketplace: string;
    revenue: number;
    quantity: number;
    returnsTotal: number;
    costPriceTotal: number;
    costPriceUnit: number;
    commission: number;
    logistics: number;
    otherCosts: number;
    totalCosts: number;
    profit: number;
    marginPercent: number | null;
    roiPercent: number | null;
    otherCostsBreakdown: CostGroupBreakdown[];
    allCostsBreakdown: CostGroupBreakdown[];
}

export interface UnitExtendedTotals {
    revenue: number;
    quantity: number;
    returnsTotal: number;
    costPriceTotal: number;
    commission: number;
    logistics: number;
    otherCosts: number;
    totalCosts: number;
    profit: number;
    marginPercent: number | null;
    roiPercent: number | null;
}

export interface UnitExtendedResponse {
    items: UnitExtendedItem[];
    totals: UnitExtendedTotals;
}
