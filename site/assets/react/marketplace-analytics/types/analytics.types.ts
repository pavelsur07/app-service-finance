export interface SnapshotSummaryTotals {
    revenue: string;
    refunds: string;
    sales_quantity: number;
    orders_quantity: number;
}

export interface SnapshotListingSummary {
    listing_id: string;
    listing_name: string | null;
    marketplace_sku: string;
    marketplace: string;
    revenue: string;
    refunds: string;
    cost_price: string | null;
    profit_total: string | null;
    roi: number | null;
    sales_quantity: number;
    returns_quantity: number;
    orders_quantity: number;
    data_quality: string[];
}

export interface SnapshotSummaryResponse {
    period: {
        date_from: string;
        date_to: string;
    };
    totals: SnapshotSummaryTotals;
    listings: SnapshotListingSummary[];
}

export interface SnapshotItem {
    id: string;
    listing_id: string;
    listing_name: string;
    listing_sku: string;
    marketplace: string;
    snapshot_date: string;
    revenue: string;
    refunds: string;
    sales_quantity: number;
    returns_quantity: number;
    orders_quantity: number;
    delivered_quantity: number;
    avg_sale_price: string;
    cost_price: string | null;
    total_cost_price: string | null;
    cost_breakdown: Record<string, unknown>;
    advertising_details: Record<string, unknown>;
    data_quality: string[];
    calculated_at: string;
}

export interface SnapshotsPaginatedResponse {
    data: SnapshotItem[];
    meta: {
        total: number;
        page: number;
        per_page: number;
        pages: number;
    };
}

export interface RecalculateJobResponse {
    job_id: string;
    status: string;
    message: string;
    marketplace: string;
    date_from: string;
    date_to: string;
}

export interface MarketplaceOption {
    value: string;
    label: string;
}
