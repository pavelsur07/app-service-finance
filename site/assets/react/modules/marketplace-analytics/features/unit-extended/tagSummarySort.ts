import type { TagSummaryRow } from './unitExtended.types';

export type TagSummarySortField =
    | 'name'
    | 'listingsCount'
    | 'revenue'
    | 'quantity'
    | 'averageCheckRub'
    | 'costPriceTotal'
    | 'stockCapitalRub'
    | 'adSpend'
    | 'totalCosts'
    | 'profit'
    | 'marginPercent';

export type TagSummarySortDirection = 'asc' | 'desc';

export function sortTagSummary(
    rows: readonly TagSummaryRow[],
    field: TagSummarySortField,
    direction: TagSummarySortDirection,
): TagSummaryRow[] {
    // Array.sort is stable: equal values retain their API order.
    return [...rows].sort((a, b) => {
        if (a.tagId === null || b.tagId === null) {
            if (a.tagId === b.tagId) return 0;
            return a.tagId === null ? 1 : -1;
        }

        let comparison: number;
        if (field === 'name') {
            comparison = a.name.localeCompare(b.name, 'ru');
        } else {
            const valueA = a[field] ?? -Infinity;
            const valueB = b[field] ?? -Infinity;
            comparison = valueA === valueB ? 0 : valueA - valueB;
        }

        return direction === 'asc' ? comparison : -comparison;
    });
}
