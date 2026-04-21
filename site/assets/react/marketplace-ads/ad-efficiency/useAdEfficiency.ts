import { useCallback, useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import type {
    AdEfficiencyItem,
    AdEfficiencyResponse,
    AdEfficiencyTotals,
    SortBy,
    SortDir,
} from './adEfficiency.types';

interface UseAdEfficiencyParams {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    page: number;
    pageSize: number;
    sortBy: SortBy;
    sortDir: SortDir;
}

interface UseAdEfficiencyResult {
    items: AdEfficiencyItem[];
    total: number;
    page: number;
    pageSize: number;
    totals: AdEfficiencyTotals | null;
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
}

export function useAdEfficiency(params: UseAdEfficiencyParams): UseAdEfficiencyResult {
    const { isLoading, data, error, run } = useAbortableQuery<AdEfficiencyResponse>();

    const load = useCallback(() => {
        if (!params.periodFrom || !params.periodTo) {
            return;
        }

        const query: Record<string, string | number> = {
            periodFrom: params.periodFrom,
            periodTo: params.periodTo,
            page: params.page,
            pageSize: params.pageSize,
            sortBy: params.sortBy,
            sortDir: params.sortDir,
        };

        if (params.marketplace) {
            query.marketplace = params.marketplace;
        }

        void run({
            url: '/api/marketplace-ads/efficiency',
            query,
        });
    }, [
        params.marketplace,
        params.periodFrom,
        params.periodTo,
        params.page,
        params.pageSize,
        params.sortBy,
        params.sortDir,
        run,
    ]);

    useEffect(() => {
        load();
    }, [load]);

    return {
        items: data?.items ?? [],
        total: data?.total ?? 0,
        page: data?.page ?? params.page,
        pageSize: data?.pageSize ?? params.pageSize,
        totals: data?.totals ?? null,
        isLoading,
        isError: error !== null,
        errorMessage: error,
    };
}
