import { useCallback, useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import type { UnitExtendedItem, UnitExtendedTotals, UnitExtendedResponse, TagSummaryRow } from './unitExtended.types';

interface UseUnitExtendedParams {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    search?: string;
    tagIds?: string[];
    tagsMatchAll?: boolean;
    withTagSummary?: boolean;
}

interface UseUnitExtendedResult {
    items: UnitExtendedItem[];
    totals: UnitExtendedTotals | null;
    tagSummary: TagSummaryRow[];
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

        const query: Record<string, string | string[]> = {
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

        if (params.tagIds && params.tagIds.length > 0) {
            query.tags = params.tagIds;
            if (params.tagsMatchAll) {
                query.tagsMatch = 'all';
            }
        }

        if (params.withTagSummary) {
            query.withTagSummary = '1';
        }

        void run({
            url: '/api/marketplace-analytics/unit-extended',
            query,
        });
    }, [params.marketplace, params.periodFrom, params.periodTo, params.search, params.tagIds, params.tagsMatchAll, params.withTagSummary, run]);

    useEffect(() => {
        load();
    }, [load]);

    return {
        items: data?.items ?? [],
        totals: data?.totals ?? null,
        tagSummary: data?.tagSummary ?? [],
        isLoading,
        isError: error !== null,
        errorMessage: error,
    };
}
