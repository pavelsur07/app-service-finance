import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiError, httpJson } from '../../shared/http/client';
import type { RecalculateJobResponse } from '../types/analytics.types';

interface UseRecalculateResult {
    isLoading: boolean;
    error: string | null;
    lastJob: RecalculateJobResponse | null;
    recalculate: (marketplace: string, dateFrom: string, dateTo: string) => void;
}

export function useRecalculate(): UseRecalculateResult {
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [lastJob, setLastJob] = useState<RecalculateJobResponse | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    const recalculate = useCallback((marketplace: string, dateFrom: string, dateTo: string) => {
        abortRef.current?.abort();
        const ac = new AbortController();
        abortRef.current = ac;

        setIsLoading(true);
        setError(null);

        httpJson<RecalculateJobResponse>('/api/marketplace-analytics/snapshots/recalculate', {
            method: 'POST',
            body: { marketplace, dateFrom, dateTo },
            signal: ac.signal,
        })
            .then((data) => {
                if (!ac.signal.aborted) {
                    setLastJob(data);
                    setIsLoading(false);
                }
            })
            .catch((e: unknown) => {
                if (e instanceof Error && e.name === 'AbortError') return;
                const message = e instanceof ApiError
                    ? e.message
                    : 'Не удалось запустить пересчёт.';
                if (!ac.signal.aborted) {
                    setError(message);
                    setIsLoading(false);
                }
            });
    }, []);

    useEffect(() => {
        return () => {
            abortRef.current?.abort();
        };
    }, []);

    return {
        isLoading,
        error,
        lastJob,
        recalculate,
    };
}
