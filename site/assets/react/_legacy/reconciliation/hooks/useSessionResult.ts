import { useEffect } from "react";
import { useAbortableQuery } from "../../shared/hooks/useAbortableQuery";
import type { ReconciliationSession } from "../reconciliation.types";

interface UseSessionResultResult {
    isLoading: boolean;
    error: string | null;
    session: ReconciliationSession | null;
}

/**
 * Query-хук: получить результат конкретной сверки (для просмотра из истории).
 *
 * GET /api/marketplace/reconciliation/{id}
 * enabled: sessionId !== null
 */
export function useSessionResult(sessionId: string | null): UseSessionResultResult {
    const { isLoading, data, error, run } = useAbortableQuery<ReconciliationSession>();

    useEffect(() => {
        if (!sessionId) return;

        void run({
            url: `/api/marketplace/reconciliation/${sessionId}`,
        });
    }, [sessionId, run]);

    return {
        isLoading,
        error,
        session: data ?? null,
    };
}
