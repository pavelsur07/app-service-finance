import { httpJson } from '../../../shared/http/client';
import type { WidgetsApiResponse } from './widgets.types';

/**
 * Получить сводку виджетов MarketplaceAnalytics за период
 * (current + previous + period).
 */
export function fetchWidgetsSummary(
    marketplace: string,
    periodFrom: string,
    periodTo: string,
    signal?: AbortSignal,
): Promise<WidgetsApiResponse> {
    return httpJson<WidgetsApiResponse>(
        '/api/marketplace-analytics/unit-extended/widgets',
        {
            method: 'GET',
            query: {
                marketplace,
                periodFrom,
                periodTo,
            },
            signal,
        },
    );
}
