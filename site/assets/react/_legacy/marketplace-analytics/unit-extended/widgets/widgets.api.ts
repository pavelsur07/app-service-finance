import { httpJson } from '../../../shared/http/client';
import type { WidgetsApiResponse } from './widgets.types';

/**
 * Получить сводку виджетов MarketplaceAnalytics за период
 * (current + previous + period).
 *
 * Теги передаются теми же параметрами, что и в /unit-extended, — виджеты и
 * таблица под ними обязаны считать один и тот же набор листингов.
 */
export function fetchWidgetsSummary(
    marketplace: string,
    periodFrom: string,
    periodTo: string,
    tagIds: string[],
    tagsMatchAll: boolean,
    signal?: AbortSignal,
): Promise<WidgetsApiResponse> {
    const query: Record<string, string | string[]> = {
        marketplace,
        periodFrom,
        periodTo,
    };

    if (tagIds.length > 0) {
        query.tags = tagIds;
        if (tagsMatchAll) {
            query.tagsMatch = 'all';
        }
    }

    return httpJson<WidgetsApiResponse>(
        '/api/marketplace-analytics/unit-extended/widgets',
        {
            method: 'GET',
            query,
            signal,
        },
    );
}
