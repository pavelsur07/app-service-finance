import { useState, useEffect, useCallback, useMemo } from 'react';

export function useDashboardSnapshot(preset: string, customFrom: string, customTo: string) {
    const [data, setData] = useState<any>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [retryTick, setRetryTick] = useState(0);

    const queryString = useMemo(() => {
        return (customFrom && customTo)
            ? `from=${encodeURIComponent(customFrom)}&to=${encodeURIComponent(customTo)}`
            : `preset=${encodeURIComponent(preset)}`;
    }, [customFrom, customTo, preset]);

    const fetchSnapshot = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await fetch(`/api/dashboard/v1/snapshot?${queryString}`);
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            setData(await response.json());
        } catch (err: any) {
            setError(err.message || 'Ошибка загрузки');
        } finally {
            setLoading(false);
        }
    }, [queryString]);

    useEffect(() => { fetchSnapshot(); }, [fetchSnapshot, retryTick]);

    return { data, loading, error, retry: () => setRetryTick(t => t + 1) };
}
