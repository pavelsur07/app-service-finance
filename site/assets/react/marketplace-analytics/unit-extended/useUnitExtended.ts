import { useCallback, useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import type { UnitExtendedItem, UnitExtendedTotals, UnitExtendedResponse } from './unitExtended.types';

interface UseUnitExtendedParams {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    search?: string;
}

interface UseUnitExtendedResult {
    items: UnitExtendedItem[];
    totals: UnitExtendedTotals | null;
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
}

export function useUnitExtended(params: UseUnitExtendedParams): UseUnitExtendedResult {
    const { isLoading, data, error, run } = useAbortableQuery<UnitExtendedResponse>();

    const load = useCallback(() => {
        if (!params.periodFrom || !params.periodTo) {
            return;
        }

        const query: Record<string, string> = {
            periodFrom: params.periodFrom,
            periodTo: params.periodTo,
        };

        if (params.marketplace) {
            query.marketplace = params.marketplace;
        }

        const search = params.search?.trim();
        if (search) {
            query.search = search;
        }

        void run({
            url: '/api/marketplace-analytics/unit-extended',
            query,
        });
    }, [params.marketplace, params.periodFrom, params.periodTo, params.search, run]);

    useEffect(() => {
        load();
    }, [load]);

    return {
        items: data?.items ?? [],
        totals: data?.totals ?? null,
        isLoading,
        isError: error !== null,
        errorMessage: error,
    };
}
