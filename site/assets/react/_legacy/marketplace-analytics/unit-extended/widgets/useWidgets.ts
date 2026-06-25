import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError } from '../../../shared/http/client';
import type { CostGroupBreakdown } from '../unitExtended.types';
import { fetchWidgetsSummary } from './widgets.api';
import { WIDGET_KEY_TO_GROUPS } from './widgetsConfig';
import type { WidgetsApiResponse, WidgetsSummary } from './widgets.types';

interface UseWidgetsParams {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
}

interface UseWidgetsResult {
    summary: WidgetsApiResponse | null;
    isLoading: boolean;
    error: string | null;
    expandedKey: string | null;
    expandedGroups: CostGroupBreakdown[];
    toggleWidget: (key: string) => void;
}

/**
 * Хук для загрузки сводки виджетов и управления раскрытием детализации.
 *
 * Использует fetchWidgetsSummary как единую точку входа в API
 * (URL и query инкапсулированы там). Abort предыдущего запроса при
 * смене параметров и при unmount.
 */
export function useWidgets(params: UseWidgetsParams): UseWidgetsResult {
    const [data, setData] = useState<WidgetsApiResponse | null>(null);
    const [isLoading, setIsLoading] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);
    const [expandedKey, setExpandedKey] = useState<string | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        if (!params.periodFrom || !params.periodTo) {
            return;
        }

        // Отменяем предыдущий запрос
        abortRef.current?.abort();
        const ac = new AbortController();
        abortRef.current = ac;

        setIsLoading(true);
        setError(null);

        fetchWidgetsSummary(
            params.marketplace,
            params.periodFrom,
            params.periodTo,
            ac.signal,
        )
            .then((response) => {
                if (ac.signal.aborted) {
                    return;
                }
                setData(response);
                setIsLoading(false);
            })
            .catch((e: unknown) => {
                // AbortError — не считаем ошибкой
                if ((e as { name?: string })?.name === 'AbortError' || ac.signal.aborted) {
                    return;
                }
                const message = e instanceof ApiError
                    ? e.message
                    : 'Не удалось загрузить данные. Повторите попытку.';
                setError(message);
                setIsLoading(false);
            });

        return () => {
            ac.abort();
        };
    }, [params.marketplace, params.periodFrom, params.periodTo]);

    const toggleWidget = useCallback((key: string) => {
        setExpandedKey((prev) => (prev === key ? null : key));
    }, []);

    const expandedGroups = useMemo<CostGroupBreakdown[]>(() => {
        if (!expandedKey || !data) {
            return [];
        }

        const groupNames = WIDGET_KEY_TO_GROUPS[expandedKey];
        if (!groupNames || groupNames.length === 0) {
            return [];
        }

        const current: WidgetsSummary = data.current;
        return current.widgetGroups.filter((g) => groupNames.includes(g.serviceGroup));
    }, [expandedKey, data]);

    return {
        summary: data,
        isLoading,
        error,
        expandedKey,
        expandedGroups,
        toggleWidget,
    };
}
