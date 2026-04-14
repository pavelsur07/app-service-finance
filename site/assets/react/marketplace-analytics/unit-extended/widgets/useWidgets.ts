import { useCallback, useEffect, useMemo, useState } from 'react';
import { useAbortableQuery } from '../../../shared/hooks/useAbortableQuery';
import type { CostGroupBreakdown } from '../unitExtended.types';
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
 */
export function useWidgets(params: UseWidgetsParams): UseWidgetsResult {
    const { isLoading, data, error, run } = useAbortableQuery<WidgetsApiResponse>();
    const [expandedKey, setExpandedKey] = useState<string | null>(null);

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

        void run({
            url: '/api/marketplace-analytics/unit-extended/widgets',
            query,
        });
    }, [params.marketplace, params.periodFrom, params.periodTo, run]);

    useEffect(() => {
        load();
    }, [load]);

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
