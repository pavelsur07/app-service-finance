import { useEffect, useState } from 'react';
import { ApiError } from '@/api/client';
import { fetchUnitExtended } from './unitExtended.api';
import type { UnitExtendedItem, UnitExtendedTotals, UnitExtendedResponse, TagSummaryRow, UnallocatedCosts } from './unitExtended.types';

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
    unallocated: UnallocatedCosts | null;
    tagSummary: TagSummaryRow[];
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
}

/** Таблица страницы. Смена параметров отменяет прежний запрос; ответ отменённого не применяется. */
export function useUnitExtended(params: UseUnitExtendedParams): UseUnitExtendedResult {
    const [data, setData] = useState<UnitExtendedResponse | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { marketplace, periodFrom, periodTo, search, tagIds, tagsMatchAll, withTagSummary } = params;

    useEffect(() => {
        if (!periodFrom || !periodTo) {
            return undefined;
        }
        const ac = new AbortController();
        setIsLoading(true);
        setError(null);

        fetchUnitExtended(
            { marketplace, periodFrom, periodTo, tagIds: tagIds ?? [], tagsMatchAll: tagsMatchAll ?? false },
            search?.trim() ?? '',
            withTagSummary ?? false,
            ac.signal,
        )
            .then((response) => {
                if (!ac.signal.aborted) {
                    setData(response);
                    setIsLoading(false);
                }
            })
            .catch((e: unknown) => {
                if (ac.signal.aborted) {
                    return;
                }
                setError(e instanceof ApiError ? e.message : 'Не удалось загрузить данные. Повторите попытку.');
                setIsLoading(false);
            });

        return () => ac.abort();
    }, [marketplace, periodFrom, periodTo, search, tagIds, tagsMatchAll, withTagSummary]);

    return {
        items: data?.items ?? [],
        totals: data?.totals ?? null,
        unallocated: data?.unallocated ?? null,
        tagSummary: data?.tagSummary ?? [],
        isLoading,
        isError: error !== null,
        errorMessage: error,
    };
}
