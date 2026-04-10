import { useEffect } from "react";
import { useAbortableQuery } from "../../shared/hooks/useAbortableQuery";
import type {
    ReconciliationHistoryItem,
    ReconciliationHistoryResponse,
} from "../reconciliation.types";

interface UseReconciliationHistoryResult {
    isLoading: boolean;
    error: string | null;
    items: ReconciliationHistoryItem[];
    total: number;
}

/**
 * Query-хук: список прошлых сверок с пагинацией.
 *
 * GET /api/marketplace/reconciliation/history?page={page}&limit=20
 */
export function useReconciliationHistory(page: number): UseReconciliationHistoryResult {
    const { isLoading, data, error, run } = useAbortableQuery<ReconciliationHistoryResponse>();

    useEffect(() => {
        void run({
            url: "/api/marketplace/reconciliation/history",
            query: { page, limit: 20 },
        });
    }, [page, run]);

    return {
        isLoading,
        error,
        items: data?.items ?? [],
        total: data?.total ?? 0,
    };
}
