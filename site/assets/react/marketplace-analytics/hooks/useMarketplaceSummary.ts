import { useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import type { SnapshotSummaryResponse, SnapshotSummaryTotals, SnapshotListingSummary } from '../types/analytics.types';

interface UseMarketplaceSummaryParams {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
}

interface UseMarketplaceSummaryResult {
    isLoading: boolean;
    error: string | null;
    totals: SnapshotSummaryTotals | null;
    listings: SnapshotListingSummary[];
}

export function useMarketplaceSummary(params: UseMarketplaceSummaryParams): UseMarketplaceSummaryResult {
    const { isLoading, data, error, run } = useAbortableQuery<SnapshotSummaryResponse>();

    useEffect(() => {
        if (!params.dateFrom || !params.dateTo) return;

        const query: Record<string, string> = {
            dateFrom: params.dateFrom,
            dateTo: params.dateTo,
        };
        if (params.marketplace && params.marketplace !== 'all') {
            query.marketplace = params.marketplace;
        }

        void run({
            url: '/api/marketplace-analytics/snapshots/summary',
            query,
        });
    }, [params.marketplace, params.dateFrom, params.dateTo, run]);

    return {
        isLoading,
        error,
        totals: data?.totals ?? null,
        listings: data?.listings ?? [],
    };
}
