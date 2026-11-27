import type { UnitExtendedItem } from './unitExtended.types';

export type UnitExtendedSortField =
    | 'sku'
    | 'title'
    | 'sellerArticle'
    | 'tags'
    | 'abcRevenue'
    | 'abcProfit'
    | 'revenue'
    | 'quantity'
    | 'averageCheckRub'
    | 'returnsTotal'
    | 'returnsQuantity'
    | 'costPriceTotal'
    | 'costPriceUnit'
    | 'stockQty'
    | 'stockCapitalRub'
    | 'commission'
    | 'commissionAverageRub'
    | 'adSpend'
    | 'cacRub'
    | 'drrPercent'
    | 'logistics'
    | 'otherCosts'
    | 'totalCosts'
    | 'profitUnit'
    | 'profit'
    | 'marginPercent'
    | 'roiPercent';

export type UnitExtendedSortDirection = 'asc' | 'desc';

/** Ключ сортировки по тегам: имена в порядке выдачи API, склеенные как в ячейке и в XLS. */
function tagsSortKey(item: UnitExtendedItem): string {
    return item.tags.map((tag) => tag.name).join(', ');
}

function comparator(a: UnitExtendedItem, b: UnitExtendedItem, field: UnitExtendedSortField): number {
    if (field === 'tags') {
        return tagsSortKey(a).localeCompare(tagsSortKey(b), 'ru');
    }
    if (field === 'title') {
        return a.title.localeCompare(b.title, 'ru');
    }
    if (field === 'abcRevenue' || field === 'abcProfit') {
        // Обратный порядок букв: первый клик (desc), как у чисел, ставит лучших — A — сверху.
        return b[field].localeCompare(a[field]);
    }
    if (field === 'sku') {
        return a.sku.localeCompare(b.sku, 'ru');
    }
    if (field === 'sellerArticle') {
        return (a.sellerArticle ?? '').localeCompare(b.sellerArticle ?? '', 'ru');
    }
    const valA = a[field] ?? -Infinity;
    const valB = b[field] ?? -Infinity;
    if (valA === valB) return 0;
    return valA - valB;
}

export function sortUnitExtendedItems(
    items: readonly UnitExtendedItem[],
    field: UnitExtendedSortField,
    direction: UnitExtendedSortDirection,
): UnitExtendedItem[] {
    return [...items].sort((a, b) => direction === 'asc' ? comparator(a, b, field) : comparator(b, a, field));
}
