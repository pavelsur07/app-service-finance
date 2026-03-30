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
        if (!params.marketplace || !params.dateFrom || !params.dateTo) return;

        void run({
            url: '/api/marketplace-analytics/snapshots/summary',
            query: {
                marketplace: params.marketplace,
                dateFrom: params.dateFrom,
                dateTo: params.dateTo,
            },
        });
    }, [params.marketplace, params.dateFrom, params.dateTo, run]);

    return {
        isLoading,
        error,
        totals: data?.totals ?? null,
        listings: data?.listings ?? [],
    };
}
