import { useCallback, useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import type { SnapshotsPaginatedResponse, SnapshotItem } from '../types/analytics.types';

interface UseMarketplaceSnapshotsParams {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    page: number;
    perPage?: number;
}

interface UseMarketplaceSnapshotsResult {
    isLoading: boolean;
    error: string | null;
    snapshots: SnapshotItem[];
    total: number;
    pages: number;
    reload: () => void;
}

export function useMarketplaceSnapshots(params: UseMarketplaceSnapshotsParams): UseMarketplaceSnapshotsResult {
    const { isLoading, data, error, run } = useAbortableQuery<SnapshotsPaginatedResponse>();
    const perPage = params.perPage ?? 20;

    const load = useCallback(() => {
        if (!params.marketplace || !params.dateFrom || !params.dateTo) return;

        void run({
            url: '/api/marketplace-analytics/snapshots',
            query: {
                marketplace: params.marketplace,
                dateFrom: params.dateFrom,
                dateTo: params.dateTo,
                page: params.page,
                perPage,
            },
        });
    }, [params.marketplace, params.dateFrom, params.dateTo, params.page, perPage, run]);

    useEffect(() => {
        load();
    }, [load]);

    return {
        isLoading,
        error,
        snapshots: data?.data ?? [],
        total: data?.meta?.total ?? 0,
        pages: data?.meta?.pages ?? 0,
        reload: load,
    };
}
