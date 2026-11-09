import { apiFetch } from '@/api/client';
import type { UnitExtendedResponse } from './unitExtended.types';
import type { WidgetsApiResponse } from './widgets/widgets.types';

const API_UNIT_EXTENDED = '/api/marketplace-analytics/unit-extended';
const API_UNIT_EXTENDED_WIDGETS = '/api/marketplace-analytics/unit-extended/widgets';
const API_UNIT_EXTENDED_EXPORT = '/api/marketplace-analytics/unit-extended/export';
const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

/** Фильтр страницы: таблица, карточки и XLS обязаны считать один и тот же набор листингов. */
export interface UnitExtendedFilter {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    tagIds: string[];
    tagsMatchAll: boolean;
}

/** Пустой маркетплейс сервер читает как «все» — параметр не отправляется. Теги — `tags[]` (Symfony). */
export function filterQuery(filter: UnitExtendedFilter): URLSearchParams {
    const query = new URLSearchParams({ periodFrom: filter.periodFrom, periodTo: filter.periodTo });
    if (filter.marketplace) {
        query.set('marketplace', filter.marketplace);
    }
    filter.tagIds.forEach((tagId) => query.append('tags[]', tagId));
    if (filter.tagIds.length > 0 && filter.tagsMatchAll) {
        query.set('tagsMatch', 'all');
    }

    return query;
}

export function fetchUnitExtended(
    filter: UnitExtendedFilter,
    search: string,
    withTagSummary: boolean,
    signal: AbortSignal,
): Promise<UnitExtendedResponse> {
    const query = filterQuery(filter);
    if (search !== '') {
        query.set('search', search);
    }
    if (withTagSummary) {
        query.set('withTagSummary', '1');
    }

    return apiFetch<UnitExtendedResponse>(`${API_UNIT_EXTENDED}?${query.toString()}`, { signal });
}

export function fetchWidgetsSummary(filter: UnitExtendedFilter, signal: AbortSignal): Promise<WidgetsApiResponse> {
    return apiFetch<WidgetsApiResponse>(`${API_UNIT_EXTENDED_WIDGETS}?${filterQuery(filter).toString()}`, { signal });
}

export interface ExportFile {
    blob: Blob;
    filename: string;
}

/** XLS — файл, не JSON, поэтому мимо `apiFetch`. Редирект на вход — ошибка, а не файл. */
export async function downloadUnitExtendedExport(filter: UnitExtendedFilter): Promise<ExportFile> {
    const response = await fetch(`${API_UNIT_EXTENDED_EXPORT}?${filterQuery(filter).toString()}`, {
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    const contentType = response.headers.get('Content-Type') ?? '';
    if (response.redirected || !contentType.startsWith(XLSX_MIME)) {
        throw new Error('Unexpected response (likely auth redirect)');
    }
    const fallback = `unit_extended_${filter.marketplace || 'all'}_${filter.periodFrom}_${filter.periodTo}.xlsx`;
    const match = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') ?? '');

    return { blob: await response.blob(), filename: match?.[1] ?? fallback };
}
