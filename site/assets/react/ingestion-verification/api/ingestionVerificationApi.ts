import { useCallback, useEffect } from 'react';
import { useAbortableQuery } from '../../shared/hooks/useAbortableQuery';
import { currentMonthDateRange } from '../utils/date';
import type {
    CoverageQuery,
    CoverageResponse,
    FinancialSummaryQuery,
    FinancialSummaryResponse,
    IssuesQuery,
    IssuesResponse,
    ReconciliationQuery,
    ReconciliationResponse,
} from '../types';

interface QueryResult<T> {
    data: T;
    isLoading: boolean;
    isError: boolean;
    errorMessage: string | null;
    reload: () => void;
}

interface CoverageParams {
    from: string;
    to: string;
    shopRef: string | null;
}

interface ReconciliationParams {
    shopRef: string | null;
    year: number;
    month: number;
}

interface IssuesParams {
    shopRef: string | null;
    year: number | null;
    month: number | null;
    page: number;
    limit: number;
}

interface FinancialSummaryParams {
    shopRef: string | null;
    yearFrom: number;
    monthFrom: number;
    yearTo: number;
    monthTo: number;
}

const EMPTY_COVERAGE: CoverageResponse = { cells: [], shops: [] };
const EMPTY_RECONCILIATION: ReconciliationResponse = { summary: undefined, by_type: [] };
const EMPTY_ISSUES: IssuesResponse = {
    items: [],
    meta: { page: 1, limit: 50, total: 0, total_pages: 0 },
};
const EMPTY_FINANCIAL_SUMMARY: FinancialSummaryResponse = { by_month: [], by_category: [] };

function nullableShopRef(shopRef: string | null): string | undefined {
    return shopRef === null || shopRef.trim() === '' ? undefined : shopRef.trim();
}

export function useCoverageData(params: CoverageParams, enabled = true): QueryResult<CoverageResponse> {
    const { isLoading, data, error, run } = useAbortableQuery<CoverageResponse>();

    const reload = useCallback((): void => {
        if (!enabled || params.from === '' || params.to === '') {
            return;
        }

        const query: CoverageQuery = {
            from: params.from,
            to: params.to,
        };
        const shopRef = nullableShopRef(params.shopRef);

        if (shopRef !== undefined) {
            query.shop_ref = shopRef;
        }

        void run({
            url: '/api/ingestion/verification/coverage',
            query,
        });
    }, [enabled, params.from, params.shopRef, params.to, run]);

    useEffect(() => {
        reload();
    }, [reload]);

    return {
        data: data ?? EMPTY_COVERAGE,
        isLoading,
        isError: error !== null,
        errorMessage: error,
        reload,
    };
}

export function useShopOptions(enabled = true): QueryResult<CoverageResponse> {
    const range = currentMonthDateRange();

    return useCoverageData({
        from: range.from,
        to: range.to,
        shopRef: null,
    }, enabled);
}

export function useReconciliationData(
    params: ReconciliationParams,
    enabled = true,
): QueryResult<ReconciliationResponse> {
    const { isLoading, data, error, run } = useAbortableQuery<ReconciliationResponse>();

    const reload = useCallback((): void => {
        const shopRef = nullableShopRef(params.shopRef);

        if (!enabled || shopRef === undefined) {
            return;
        }

        const query: ReconciliationQuery = {
            shop_ref: shopRef,
            year: params.year,
            month: params.month,
        };

        void run({
            url: '/api/ingestion/verification/reconciliation',
            query,
        });
    }, [enabled, params.month, params.shopRef, params.year, run]);

    useEffect(() => {
        reload();
    }, [reload]);

    return {
        data: data ?? EMPTY_RECONCILIATION,
        isLoading,
        isError: error !== null,
        errorMessage: error,
        reload,
    };
}

export function useIssuesData(params: IssuesParams, enabled = true): QueryResult<IssuesResponse> {
    const { isLoading, data, error, run } = useAbortableQuery<IssuesResponse>();

    const reload = useCallback((): void => {
        if (!enabled) {
            return;
        }

        const query: IssuesQuery = {
            page: params.page,
            limit: params.limit,
        };
        const shopRef = nullableShopRef(params.shopRef);

        if (shopRef !== undefined) {
            query.shop_ref = shopRef;
        }

        if (params.year !== null && params.month !== null) {
            query.year = params.year;
            query.month = params.month;
        }

        void run({
            url: '/api/ingestion/verification/issues',
            query,
        });
    }, [enabled, params.limit, params.month, params.page, params.shopRef, params.year, run]);

    useEffect(() => {
        reload();
    }, [reload]);

    return {
        data: data ?? EMPTY_ISSUES,
        isLoading,
        isError: error !== null,
        errorMessage: error,
        reload,
    };
}

export function useFinancialSummaryData(
    params: FinancialSummaryParams,
    enabled = true,
): QueryResult<FinancialSummaryResponse> {
    const { isLoading, data, error, run } = useAbortableQuery<FinancialSummaryResponse>();

    const reload = useCallback((): void => {
        if (!enabled) {
            return;
        }

        const query: FinancialSummaryQuery = {
            year_from: params.yearFrom,
            month_from: params.monthFrom,
            year_to: params.yearTo,
            month_to: params.monthTo,
        };
        const shopRef = nullableShopRef(params.shopRef);

        if (shopRef !== undefined) {
            query.shop_ref = shopRef;
        }

        void run({
            url: '/api/ingestion/verification/financial-summary',
            query,
        });
    }, [
        enabled,
        params.monthFrom,
        params.monthTo,
        params.shopRef,
        params.yearFrom,
        params.yearTo,
        run,
    ]);

    useEffect(() => {
        reload();
    }, [reload]);

    return {
        data: data ?? EMPTY_FINANCIAL_SUMMARY,
        isLoading,
        isError: error !== null,
        errorMessage: error,
        reload,
    };
}
