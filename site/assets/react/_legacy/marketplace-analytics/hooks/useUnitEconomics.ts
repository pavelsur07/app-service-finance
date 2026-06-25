import { useCallback, useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import type {
    UnitEconomicsRow,
    PortfolioSummary,
    UnitEconomicsMeta,
    UnitEconomicsResponse,
} from '../types/unit-economics.types';

interface UseUnitEconomicsParams {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    page: number;
}

interface UseUnitEconomicsResult {
    items: UnitEconomicsRow[];
    summary: PortfolioSummary | null;
    meta: UnitEconomicsMeta | null;
    isLoading: boolean;
    isError: boolean;
}

export function useUnitEconomics(params: UseUnitEconomicsParams): UseUnitEconomicsResult {
    const { isLoading, data, error, run } = useAbortableQuery<UnitEconomicsResponse>();

    const load = useCallback(() => {
        if (!params.dateFrom || !params.dateTo) {
            return;
        }

        const query: Record<string, string | number> = {
            date_from: params.dateFrom,
            date_to: params.dateTo,
            page: params.page,
        };

        if (params.marketplace) {
            query.marketplace = params.marketplace;
        }

        void run({
            url: '/api/marketplace-analytics/unit-economics',
            query,
        });
    }, [params.marketplace, params.dateFrom, params.dateTo, params.page, run]);

    useEffect(() => {
        load();
    }, [load]);

    return {
        items: data?.data ?? [],
        summary: data?.summary ?? null,
        meta: data?.meta ?? null,
        isLoading,
        isError: error !== null,
    };
}
