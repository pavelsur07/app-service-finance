export interface ListingTag {
    id: string;
    name: string;
}

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
    sellerArticle: string;
    marketplace: string;
    revenue: number;
    quantity: number;
    returnsTotal: number;
    returnsQuantity: number;
    costPriceTotal: number;
    costPriceUnit: number;
    /** null = остаток неизвестен (снапшота нет или он протух), а не «ноль». */
    stockQty: number | null;
    /** null = остаток или себестоимость единицы неизвестны. */
    stockCapitalRub: number | null;
    commission: number;
    commissionAverageRub: number | null;
    adSpend: number;
    cacRub: number | null;
    drrPercent: number | null;
    logistics: number;
    otherCosts: number;
    totalCosts: number;
    profit: number;
    profitUnit: number | null;
    marginPercent: number | null;
    roiPercent: number | null;
    otherCostsBreakdown: CostGroupBreakdown[];
    allCostsBreakdown: CostGroupBreakdown[];
    tags: ListingTag[];
}

export interface UnitExtendedTotals {
    revenue: number;
    quantity: number;
    returnsTotal: number;
    returnsQuantity: number;
    costPriceTotal: number;
    commission: number;
    commissionAverageRub: number | null;
    adSpend: number;
    cacRub: number | null;
    drrPercent: number | null;
    logistics: number;
    otherCosts: number;
    totalCosts: number;
    profit: number;
    profitUnit: number | null;
    marginPercent: number | null;
    roiPercent: number | null;
}

export interface TagSummaryRow {
    tagId: string | null;
    name: string;
    listingsCount: number;
    revenue: number;
    quantity: number;
    returnsTotal: number;
    returnsQuantity: number;
    costPriceTotal: number;
    /** null = ни у одного листинга группы остаток не известен. */
    stockCapitalRub: number | null;
    /** Сколько листингов группы не вошло в сумму остатка из-за неизвестного значения. */
    stockCapitalUnknownCount: number;
    commission: number;
    commissionAverageRub: number | null;
    adSpend: number;
    cacRub: number | null;
    drrPercent: number | null;
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
    tagSummary?: TagSummaryRow[];
}
