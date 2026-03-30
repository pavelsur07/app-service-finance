export interface UnitEconomicsRow {
    listing_id: string;
    listing_name: string;
    marketplace_sku: string;
    revenue: string;
    refunds: string;
    sales_quantity: number;
    returns_quantity: number;
    orders_quantity: number;
    delivered_quantity: number;
    avg_sale_price: string;
    avg_cost_price: string | null;
    total_cost_price: string | null;
    logistics_to: string;
    logistics_back: string;
    storage: string;
    advertising_cpc: string;
    advertising_other: string;
    advertising_external: string;
    commission: string;
    other_costs: string;
    has_quality_issues: boolean;
    snapshots_count: number;
}

export interface PortfolioSummary {
    total_revenue: string;
    total_refunds: string;
    total_sales_quantity: number;
    total_listings: number;
    total_profit: string | null;
}

export interface UnitEconomicsMeta {
    total: number;
    page: number;
    per_page: number;
    pages: number;
}

export interface UnitEconomicsResponse {
    data: UnitEconomicsRow[];
    summary: PortfolioSummary;
    meta: UnitEconomicsMeta;
}
