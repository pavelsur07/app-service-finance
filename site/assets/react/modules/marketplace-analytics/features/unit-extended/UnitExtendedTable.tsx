import React, { useState, useMemo, useEffect, useRef, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { SortableColumnHeader } from './SortableColumnHeader';
import type { UnallocatedCosts, UnitExtendedItem, UnitExtendedTotals } from './unitExtended.types';
import { UnitExtendedFooter, UnitExtendedItemRow } from './UnitExtendedRows';
import type { ExpandedCostType } from './UnitExtendedRows';
import { sortUnitExtendedItems } from './unitExtendedSort';
import type { UnitExtendedSortDirection, UnitExtendedSortField } from './unitExtendedSort';
import { useFixedHeader } from './useFixedHeader';
import { cn } from '@/react/shared/lib/cn';
import styles from './UnitExtendedTable.module.css';

interface UnitExtendedTableProps {
    items: UnitExtendedItem[];
    totals: UnitExtendedTotals | null;
    unallocated: UnallocatedCosts | null;
    isLoading: boolean;
    emptyMessage?: string;
    selectedTagIds: string[];
    onToggleTag: (tagId: string) => void;
}

/** Ключ раскрытия строки «Не распределено по товарам» — не пересекается с UUID листингов. */
const UNALLOCATED_KEY = '__unallocated__';

interface ExpandedState {
    listingId: string;
    type: ExpandedCostType;
}

interface FloatingScrollbarStyle {
    left: string;
    width: string;
}

function isHorizontallyScrollable(node: HTMLElement | null): boolean {
    return Boolean(node && node.scrollWidth > node.clientWidth + 1);
}

function resolveScrollContainer(wrapper: HTMLDivElement | null): HTMLElement | null {
    if (!wrapper) return null;
    if (isHorizontallyScrollable(wrapper)) return wrapper;
    let current: HTMLElement | null = wrapper.parentElement;
    while (current !== null) {
        if (current === document.body || current === document.documentElement) {
            break;
        }
        if (isHorizontallyScrollable(current)) {
            return current;
        }
        current = current.parentElement;
    }
    return wrapper;
}

interface TableHeader {
    field: UnitExtendedSortField;
    label: React.ReactNode;
    align?: string;
    tooltip?: string;
    className?: string;
    accessibleLabel?: string;
}

const HEADERS: TableHeader[] = [
    { field: 'sku', label: 'SKU' },
    { field: 'title', label: 'Наименование' },
    { field: 'sellerArticle', label: 'Артикул' },
    { field: 'tags', label: 'Теги', className: styles.tags },
    { field: 'abcRevenue', label: 'ABC выр.', align: 'text-center', tooltip: 'ABC по нетто-выручке (выручка − возвраты): A — первые 80% выручки, B — следующие 15%, C — остальные 5% и товары без выручки' },
    { field: 'abcProfit', label: 'ABC приб.', align: 'text-center', tooltip: 'ABC по прибыли: A — первые 80% прибыли, B — следующие 15%, C — остальные 5%, D — убыток (прибыль ≤ 0)' },
    { field: 'revenue', label: 'Выручка', align: 'text-end' },
    { field: 'quantity', label: 'Кол-во', align: 'text-end', accessibleLabel: 'Кол-во продаж' },
    { field: 'averageCheckRub', label: 'Сред. чек', align: 'text-end', tooltip: 'Средний чек: (выручка − возвраты) / (продано − возвращено, шт.)' },
    { field: 'returnsTotal', label: 'Возвраты', align: 'text-end' },
    { field: 'returnsQuantity', label: 'Кол-во', align: 'text-end', tooltip: 'Возвращено, шт.', accessibleLabel: 'Кол-во возвратов' },
    { field: 'costPriceTotal', label: 'Себестоимость', align: 'text-end' },
    { field: 'costPriceUnit', label: 'Себест. ед.', align: 'text-end' },
    { field: 'stockQty', label: 'Ост. шт.', align: 'text-end' },
    { field: 'stockCapitalRub', label: <>Остаток на МП<br />FBO/FBS/RFBS</>, align: 'text-end' },
    { field: 'commission', label: 'Комиссия', align: 'text-end' },
    { field: 'commissionAverageRub', label: 'Ком. сред.', align: 'text-end' },
    { field: 'adSpend', label: 'Реклама', align: 'text-end', tooltip: 'Рекламные расходы' },
    { field: 'cacRub', label: 'CAC,р', align: 'text-end' },
    { field: 'drrPercent', label: 'ДРР(п) %', align: 'text-end', tooltip: 'Доля рекламных расходов от продаж' },
    { field: 'logistics', label: 'Логистика', align: 'text-end' },
    { field: 'otherCosts', label: 'Прочие затраты', align: 'text-end' },
    { field: 'totalCosts', label: 'Итого затрат', align: 'text-end' },
    { field: 'profitUnit', label: 'Прибыль ед.', align: 'text-end', tooltip: 'Прибыль на 1 нетто-проданную единицу (продажи − возвраты)' },
    { field: 'profit', label: 'Прибыль', align: 'text-end' },
    { field: 'marginPercent', label: 'Маржа %', align: 'text-end' },
    { field: 'roiPercent', label: 'ROI %', align: 'text-end' },
];

const UnitExtendedTable: React.FC<UnitExtendedTableProps> = ({
    items,
    totals,
    unallocated,
    isLoading,
    emptyMessage = 'Нет данных за выбранный период',
    selectedTagIds,
    onToggleTag,
}) => {
    const [sortField, setSortField] = useState<UnitExtendedSortField>('revenue');
    const [sortDir, setSortDir] = useState<UnitExtendedSortDirection>('desc');
    const [expanded, setExpanded] = useState<ExpandedState | null>(null);
    const { tableWrapperRef: fixedHeaderTableWrapperRef, theadRef, showFixed, columnWidths, scrollLeft, wrapperRect } = useFixedHeader();
    const floatingScrollbarRef = useRef<HTMLDivElement | null>(null);
    const [showFloatingScrollbar, setShowFloatingScrollbar] = useState(false);
    const [floatingScrollbarStyle, setFloatingScrollbarStyle] = useState<FloatingScrollbarStyle>({
        left: '0px',
        width: '0px',
    });
    const isSyncingRef = useRef(false);
    const wrapperNodeRef = useRef<HTMLDivElement | null>(null);
    const scrollContainerRef = useRef<HTMLElement | null>(null);

    const tableWrapperRef = useCallback((node: HTMLDivElement | null) => {
        wrapperNodeRef.current = node;
        fixedHeaderTableWrapperRef(node);
    }, [fixedHeaderTableWrapperRef]);

    const syncFloatingState = useCallback(() => {
        const wrapper = wrapperNodeRef.current;
        const floating = floatingScrollbarRef.current;
        if (!wrapper || !floating) {
            setShowFloatingScrollbar(false);
            return;
        }
        const scrollContainer = scrollContainerRef.current ?? wrapper;
        if (!scrollContainer) {
            setShowFloatingScrollbar(false);
            return;
        }

        const table = wrapper.querySelector(`.${styles.table}`) as HTMLTableElement | null;
        const contentScrollWidth = table?.scrollWidth ?? wrapper.scrollWidth;
        const rect = wrapper.getBoundingClientRect();
        const viewportLeft = Math.max(rect.left, 0);
        const viewportRight = Math.min(rect.right, window.innerWidth);
        const visibleWidth = Math.max(0, viewportRight - viewportLeft);
        if (visibleWidth === 0 || rect.width === 0) {
            setShowFloatingScrollbar(false);
            return;
        }
        const hasHorizontalOverflow = contentScrollWidth > visibleWidth + 1;
        const isTableVisible = rect.top < window.innerHeight && rect.bottom > 0;
        const shouldShow = hasHorizontalOverflow && isTableVisible;

        setShowFloatingScrollbar(shouldShow);
        const left = Math.max(rect.left, 0);
        const right = Math.min(rect.right, window.innerWidth);
        const width = Math.max(0, right - left);
        setFloatingScrollbarStyle({
            left: `${left}px`,
            width: `${width}px`,
        });

        if (floating.scrollLeft !== scrollContainer.scrollLeft) {
            floating.scrollLeft = scrollContainer.scrollLeft;
        }
    }, []);

    const sorted = useMemo(
        () => sortUnitExtendedItems(items, sortField, sortDir),
        [items, sortField, sortDir],
    );

    const handleSort = (field: UnitExtendedSortField) => {
        if (field === sortField) {
            setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortField(field);
            setSortDir('desc');
        }
    };

    const handleExpandToggle = (listingId: string, type: ExpandedCostType) => {
        setExpanded((prev) => {
            if (prev && prev.listingId === listingId && prev.type === type) {
                return null;
            }
            return { listingId, type };
        });
    };

    useEffect(() => {
        const wrapper = wrapperNodeRef.current;
        const floating = floatingScrollbarRef.current;
        if (!wrapper || !floating) return;
        const scrollContainer = resolveScrollContainer(wrapper) ?? wrapper;
        scrollContainerRef.current = scrollContainer;
        if (!scrollContainer) return;

        const syncWidths = () => {
            const inner = floating.firstElementChild as HTMLDivElement | null;
            if (!inner) return;
            const table = wrapper.querySelector(`.${styles.table}`) as HTMLTableElement | null;
            const contentScrollWidth = table?.scrollWidth ?? wrapper.scrollWidth;
            inner.style.width = `${contentScrollWidth}px`;
            syncFloatingState();
        };

        const onWrapperScroll = () => {
            if (isSyncingRef.current) return;
            const nextScrollContainer = scrollContainerRef.current ?? scrollContainer;
            if (!nextScrollContainer) return;
            if (floating.scrollLeft !== nextScrollContainer.scrollLeft) {
                isSyncingRef.current = true;
                floating.scrollLeft = nextScrollContainer.scrollLeft;
                isSyncingRef.current = false;
            }
        };

        const onFloatingScroll = () => {
            if (isSyncingRef.current) return;
            const nextScrollContainer = scrollContainerRef.current ?? scrollContainer;
            if (!nextScrollContainer) return;
            if (nextScrollContainer.scrollLeft !== floating.scrollLeft) {
                isSyncingRef.current = true;
                nextScrollContainer.scrollLeft = floating.scrollLeft;
                isSyncingRef.current = false;
            }
        };

        scrollContainer.addEventListener('scroll', onWrapperScroll);
        floating.addEventListener('scroll', onFloatingScroll);
        window.addEventListener('scroll', syncFloatingState, { passive: true });
        window.addEventListener('resize', syncWidths);

        let resizeObserver: ResizeObserver | null = null;
        if (typeof ResizeObserver !== 'undefined') {
            resizeObserver = new ResizeObserver(syncWidths);
            resizeObserver.observe(wrapper);
            if (wrapper.firstElementChild) {
                resizeObserver.observe(wrapper.firstElementChild);
            }
        }

        syncWidths();

        return () => {
            scrollContainer.removeEventListener('scroll', onWrapperScroll);
            floating.removeEventListener('scroll', onFloatingScroll);
            window.removeEventListener('scroll', syncFloatingState);
            window.removeEventListener('resize', syncWidths);
            resizeObserver?.disconnect();
        };
    }, [items, totals, syncFloatingState]);

    // Renders header row for both the real thead and the portal clone.
    // When `widths` are provided (clone mode), each th gets a fixed pixel width.
    // The frozen first column uses a counter-transform to stay visible despite the
    // table's translateX(-scrollLeft) shift.
    const renderHeaderCells = (widths?: number[]) => {
        const mirror = widths !== undefined;
        const getWidthStyle = (i: number): React.CSSProperties => {
            const w = widths?.[i];
            return w !== undefined ? { width: w, minWidth: w, maxWidth: w } : {};
        };

        const frozenClass = (field: UnitExtendedSortField): string => {
            if (field === 'sku') return `${styles.frozen} ${styles.frozenSku}`;
            if (field === 'title') return `${styles.frozen} ${styles.frozenTitle}`;
            return '';
        };

        return (
            <tr>
                {HEADERS.map((h, i) => {
                    const isFrozen = h.field === 'sku' || h.field === 'title';
                    return (
                        <SortableColumnHeader
                            key={h.field}
                            className={cn(h.align, h.className, frozenClass(h.field))}
                            style={{
                                ...getWidthStyle(i),
                                ...(mirror && isFrozen
                                    ? {
                                        position: 'relative' as const,
                                        zIndex: 10,
                                        background: 'var(--tblr-bg-surface)',
                                        // Counter-act the table's translateX(-scrollLeft) so the
                                        // frozen columns stay anchored at the left edge of the clone.
                                        transform: `translateX(${scrollLeft}px)`,
                                      }
                                    : {}),
                            }}
                            title={h.tooltip}
                            accessibleLabel={h.accessibleLabel}
                            direction={sortField === h.field ? sortDir : undefined}
                            onSort={() => handleSort(h.field)}
                            mirror={mirror}
                        >
                            {h.label}
                        </SortableColumnHeader>
                    );
                })}
                <th scope="col" className="text-end" style={getWidthStyle(HEADERS.length)}>
                    Все затраты
                </th>
            </tr>
        );
    };

    if (isLoading) {
        return (
            <div className="d-flex justify-content-center py-4">
                <div className="spinner-border text-primary" role="status">
                    <span className="visually-hidden">Загрузка...</span>
                </div>
            </div>
        );
    }

    if (items.length === 0) {
        return (
            <div className="empty">
                <div className="empty-img">
                    <i className="ti ti-package text-muted fs-1" />
                </div>
                <p className="empty-title">{emptyMessage}</p>
            </div>
        );
    }

    const colCount = HEADERS.length + 1;
    const isUnallocatedExpanded = expanded?.listingId === UNALLOCATED_KEY && expanded?.type === 'all';

    return (
        <>
            {showFixed && wrapperRect && createPortal(
                <div aria-hidden="true" style={{
                    position: 'fixed',
                    top: 0,
                    left: wrapperRect.left,
                    width: wrapperRect.width,
                    zIndex: 1050,
                    overflow: 'hidden',
                    background: 'var(--tblr-bg-surface)',
                    borderBottom: '2px solid var(--tblr-border-color)',
                    boxShadow: '0 2px 4px rgba(0,0,0,0.08)',
                }}>
                    <table className="table table-vcenter card-table" style={{
                        borderCollapse: 'separate',
                        borderSpacing: 0,
                        width: 'max-content',
                        minWidth: '100%',
                        margin: 0,
                        transform: `translateX(-${scrollLeft}px)`,
                    }}>
                        <thead>
                            {renderHeaderCells(columnWidths)}
                        </thead>
                    </table>
                </div>,
                document.body
            )}

            <div ref={tableWrapperRef} className={styles.scroll}>
                <table className={`table table-vcenter card-table ${styles.table}`}>
                    <thead ref={theadRef}>
                        {renderHeaderCells()}
                    </thead>
                    <tbody>
                        {sorted.map((row) => (
                            <UnitExtendedItemRow
                                key={row.listingId}
                                row={row}
                                colCount={colCount}
                                expandedType={expanded?.listingId === row.listingId ? expanded.type : undefined}
                                selectedTagIds={selectedTagIds}
                                onToggleTag={onToggleTag}
                                onExpandToggle={handleExpandToggle}
                            />
                        ))}
                    </tbody>
                    {totals && (
                        <UnitExtendedFooter
                            totals={totals}
                            unallocated={unallocated}
                            colCount={colCount}
                            isUnallocatedExpanded={isUnallocatedExpanded}
                            onUnallocatedToggle={() => handleExpandToggle(UNALLOCATED_KEY, 'all')}
                        />
                    )}
                </table>
            </div>
            {createPortal(
                <div
                    ref={floatingScrollbarRef}
                    className={cn(styles.floatingScrollbar, !showFloatingScrollbar && styles.hidden)}
                    style={floatingScrollbarStyle}
                    aria-hidden="true"
                >
                    <div className={styles.floatingScrollbarInner} />
                </div>,
                document.body
            )}
        </>
    );
};

export default UnitExtendedTable;
