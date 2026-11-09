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

/** A/B/C — доля во вкладе 80/15/5 по накопленной сумме, D — убыток (только прибыль). */
export type AbcClass = 'A' | 'B' | 'C' | 'D';

export interface UnitExtendedItem {
    listingId: string;
    title: string;
    sku: string;
    sellerArticle: string;
    marketplace: string;
    revenue: number;
    quantity: number;
    /** (Выручка − возвраты) / (продано − возвращено, шт.); null = нетто-продаж нет. */
    averageCheckRub: number | null;
    returnsTotal: number;
    returnsQuantity: number;
    costPriceTotal: number;
    /** null = нет проданных единиц с известной себестоимостью, а не «себестоимость ноль». */
    costPriceUnit: number | null;
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
    /** По нетто-выручке (выручка − возвраты) всего набора; ≤ 0 → C. */
    abcRevenue: AbcClass;
    /** По прибыли всего набора; ≤ 0 → D. */
    abcProfit: AbcClass;
}

export interface UnitExtendedTotals {
    revenue: number;
    quantity: number;
    /** (Выручка − возвраты) / (продано − возвращено, шт.); null = нетто-продаж нет. */
    averageCheckRub: number | null;
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

/**
 * Затраты маркетплейса без привязки к товару (хранение, акции, подписки…) — строка
 * «Не распределено по товарам» перед «Итого»; уже входят в totals. null — фильтр по
 * тегам или таких затрат нет. Реклама сюда не входит: она в колонке «Реклама» из статистики.
 */
export interface UnallocatedCosts {
    commission: number;
    logistics: number;
    otherCosts: number;
    totalCosts: number;
    profit: number;
    allCostsBreakdown: CostGroupBreakdown[];
}

export interface TagSummaryRow {
    tagId: string | null;
    name: string;
    listingsCount: number;
    revenue: number;
    quantity: number;
    /** (Выручка − возвраты) / (продано − возвращено, шт.); null = нетто-продаж нет. */
    averageCheckRub: number | null;
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
    unallocated: UnallocatedCosts | null;
    tagSummary?: TagSummaryRow[];
}

export interface MarketplaceOption {
    value: string;
    label: string;
}
