import { useCallback, useEffect, useMemo, useState } from 'react';
import type { DashboardSnapshotResponse } from '../types';

interface UseDashboardSnapshotResult {
  data: DashboardSnapshotResponse | null;
  loading: boolean;
  error: string | null;
  retry: () => void;
}

export function useDashboardSnapshot(
  preset: string,
  customFrom: string,
  customTo: string,
): UseDashboardSnapshotResult {
  const [data, setData] = useState<DashboardSnapshotResponse | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [retryTick, setRetryTick] = useState<number>(0);

  const isCustom = customFrom !== '' && customTo !== '';

  const queryString = useMemo(() => {
    if (isCustom) {
      return `from=${encodeURIComponent(customFrom)}&to=${encodeURIComponent(customTo)}`;
    }

    return `preset=${encodeURIComponent(preset)}`;
  }, [isCustom, customFrom, customTo, preset]);

  const fetchSnapshot = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await fetch(`/api/dashboard/v1/snapshot?${queryString}`, {
        headers: { Accept: 'application/json' },
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const payload: DashboardSnapshotResponse = await response.json();
      setData(payload);
    } catch (fetchError: unknown) {
      setError(fetchError instanceof Error ? fetchError.message : 'Не удалось загрузить dashboard snapshot');
      setData(null);
    } finally {
      setLoading(false);
    }
  }, [queryString]);

  useEffect(() => {
    fetchSnapshot();
  }, [fetchSnapshot, retryTick]);

  const retry = useCallback(() => {
    setRetryTick((previousTick) => previousTick + 1);
  }, []);

  return { data, loading, error, retry };
}
