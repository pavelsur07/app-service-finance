import React, { useState, useMemo } from 'react';
import { createPortal } from 'react-dom';
import type { UnitExtendedItem, UnitExtendedTotals } from './unitExtended.types';
import CostsBreakdown from './CostsBreakdown';
import { formatMoney } from '../utils/utils';
import { useFixedHeader } from './useFixedHeader';

type SortField =
    | 'sku'
    | 'title'
    | 'revenue'
    | 'quantity'
    | 'returnsTotal'
    | 'costPriceTotal'
    | 'costPriceUnit'
    | 'commission'
    | 'adSpend'
    | 'drrPercent'
    | 'logistics'
    | 'otherCosts'
    | 'totalCosts'
    | 'profit'
    | 'marginPercent'
    | 'roiPercent';

type SortDir = 'asc' | 'desc';

interface UnitExtendedTableProps {
    items: UnitExtendedItem[];
    totals: UnitExtendedTotals | null;
    isLoading: boolean;
}

const TABLE_STYLES = `
.ue-ext-scroll {
    overflow-x: auto;
}
.ue-ext-table {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    margin: 0;
}
.ue-ext-table td.ue-ext-frozen,
.ue-ext-table th.ue-ext-frozen {
    position: sticky;
    background: var(--tblr-bg-surface);
}
.ue-ext-table td.ue-ext-frozen-sku,
.ue-ext-table th.ue-ext-frozen-sku {
    left: 0;
    min-width: 140px;
    max-width: 140px;
    width: 140px;
}
.ue-ext-table td.ue-ext-frozen-title,
.ue-ext-table th.ue-ext-frozen-title {
    left: 140px;
    min-width: 240px;
    max-width: 240px;
    width: 240px;
}
.ue-ext-table td.ue-ext-frozen { z-index: 1; }
.ue-ext-table thead th.ue-ext-frozen { z-index: 3; }
.ue-ext-table tfoot td.ue-ext-frozen { z-index: 1; }
.ue-ext-table .ue-ext-frozen-title::after {
    content: '';
    position: absolute;
    top: 0;
    right: -4px;
    bottom: 0;
    width: 4px;
    background: linear-gradient(to right, rgba(0,0,0,0.08), transparent);
    pointer-events: none;
}
.ue-ext-table tbody tr:hover td {
    background: var(--tblr-bg-surface-secondary);
}
.ue-ext-table tbody tr:hover td.ue-ext-frozen {
    background: var(--tblr-bg-surface-secondary);
}
`;

type ExpandedType = 'other' | 'all';

interface ExpandedState {
    listingId: string;
    type: ExpandedType;
}

const HEADERS: { field: SortField; label: string; align?: string; tooltip?: string }[] = [
    { field: 'sku', label: 'SKU' },
    { field: 'title', label: 'Наименование' },
    { field: 'revenue', label: 'Выручка', align: 'text-end' },
    { field: 'quantity', label: 'Кол-во', align: 'text-end' },
    { field: 'returnsTotal', label: 'Возвраты', align: 'text-end' },
    { field: 'costPriceTotal', label: 'Себестоимость', align: 'text-end' },
    { field: 'costPriceUnit', label: 'Себест. ед.', align: 'text-end' },
    { field: 'commission', label: 'Комиссия', align: 'text-end' },
    { field: 'adSpend', label: 'РР', align: 'text-end', tooltip: 'Рекламные расходы' },
    { field: 'drrPercent', label: 'ДРР(п) %', align: 'text-end', tooltip: 'Доля рекламных расходов от продаж' },
    { field: 'logistics', label: 'Логистика', align: 'text-end' },
    { field: 'otherCosts', label: 'Прочие затраты', align: 'text-end' },
    { field: 'totalCosts', label: 'Итого затрат', align: 'text-end' },
    { field: 'profit', label: 'Прибыль', align: 'text-end' },
    { field: 'marginPercent', label: 'Маржа %', align: 'text-end' },
    { field: 'roiPercent', label: 'ROI %', align: 'text-end' },
];

function comparator(a: UnitExtendedItem, b: UnitExtendedItem, field: SortField): number {
    if (field === 'title') {
        return a.title.localeCompare(b.title, 'ru');
    }
    if (field === 'sku') {
        return a.sku.localeCompare(b.sku, 'ru');
    }
    const valA = (a[field] as number | null) ?? -Infinity;
    const valB = (b[field] as number | null) ?? -Infinity;
    if (valA === valB) return 0;
    return valA - valB;
}

function marginColor(v: number | null): string {
    if (v === null) return '';
    if (v < 5) return 'text-red';
    if (v <= 15) return 'text-yellow';
    return 'text-green';
}

function roiColor(v: number | null): string {
    return v !== null && v < 0 ? 'text-red' : '';
}

function formatPercent(v: number | null): React.ReactNode {
    if (v === null) return '—';
    return `${v.toFixed(1)}%`;
}

const UnitExtendedTable: React.FC<UnitExtendedTableProps> = ({ items, totals, isLoading }) => {
    const [sortField, setSortField] = useState<SortField>('revenue');
    const [sortDir, setSortDir] = useState<SortDir>('desc');
    const [expanded, setExpanded] = useState<ExpandedState | null>(null);
    const { tableWrapperRef, theadRef, showFixed, columnWidths, scrollLeft, wrapperRect } = useFixedHeader();

    const sorted = useMemo(() => {
        const copy = [...items];
        copy.sort((a, b) => {
            const cmp = comparator(a, b, sortField);
            return sortDir === 'asc' ? cmp : -cmp;
        });
        return copy;
    }, [items, sortField, sortDir]);

    const handleSort = (field: SortField) => {
        if (field === sortField) {
            setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortField(field);
            setSortDir('desc');
        }
    };

    const handleExpandToggle = (listingId: string, type: ExpandedType) => {
        setExpanded((prev) => {
            if (prev && prev.listingId === listingId && prev.type === type) {
                return null;
            }
            return { listingId, type };
        });
    };

    // Renders header row for both the real thead and the portal clone.
    // When `widths` are provided (clone mode), each th gets a fixed pixel width.
    // The frozen first column uses a counter-transform to stay visible despite the
    // table's translateX(-scrollLeft) shift.
    const renderHeaderCells = (widths?: number[]) => {
        const getWidthStyle = (i: number): React.CSSProperties => {
            const w = widths?.[i];
            return w !== undefined ? { width: w, minWidth: w, maxWidth: w } : {};
        };

        const frozenClass = (field: SortField): string => {
            if (field === 'sku') return 'ue-ext-frozen ue-ext-frozen-sku';
            if (field === 'title') return 'ue-ext-frozen ue-ext-frozen-title';
            return '';
        };

        return (
            <tr>
                {HEADERS.map((h, i) => {
                    const isFrozen = h.field === 'sku' || h.field === 'title';
                    return (
                        <th
                            key={h.field}
                            className={`${h.align ?? ''} ${frozenClass(h.field)}`}
                            style={{
                                cursor: 'pointer',
                                ...getWidthStyle(i),
                                ...(widths !== undefined && isFrozen
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
                            onClick={() => handleSort(h.field)}
                        >
                            {h.label}
                            {sortField === h.field && (
                                <i className={`ti ti-chevron-${sortDir === 'asc' ? 'up' : 'down'} ms-1`} />
                            )}
                        </th>
                    );
                })}
                <th className="text-end" style={getWidthStyle(HEADERS.length)}>
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
                <p className="empty-title">Нет данных за выбранный период</p>
            </div>
        );
    }

    const colCount = HEADERS.length + 1;

    return (
        <>
            <style>{TABLE_STYLES}</style>

            {showFixed && wrapperRect && createPortal(
                <div style={{
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

            <div ref={tableWrapperRef} className="ue-ext-scroll">
                <table className="table table-vcenter card-table ue-ext-table">
                    <thead ref={theadRef}>
                        {renderHeaderCells()}
                    </thead>
                    <tbody>
                        {sorted.map((row) => {
                            const isOtherExpanded = expanded?.listingId === row.listingId && expanded?.type === 'other';
                            const isAllExpanded = expanded?.listingId === row.listingId && expanded?.type === 'all';

                            return (
                                <React.Fragment key={row.listingId}>
                                    <tr>
                                        <td className="ue-ext-frozen ue-ext-frozen-sku">
                                            <div className="text-muted small text-truncate">{row.sku || '—'}</div>
                                        </td>
                                        <td className="ue-ext-frozen ue-ext-frozen-title">
                                            <div className="text-truncate">{row.title || '—'}</div>
                                        </td>
                                        <td className="text-end">{formatMoney(row.revenue)}</td>
                                        <td className="text-end">{row.quantity.toLocaleString('ru-RU')}</td>
                                        <td className="text-end text-red">{formatMoney(row.returnsTotal)}</td>
                                        <td className="text-end">{formatMoney(row.costPriceTotal)}</td>
                                        <td className="text-end">{formatMoney(row.costPriceUnit)}</td>
                                        <td className="text-end">{formatMoney(row.commission)}</td>
                                        <td className="text-end">{formatMoney(row.adSpend)}</td>
                                        <td className="text-end">{formatPercent(row.drrPercent)}</td>
                                        <td className="text-end">{formatMoney(row.logistics)}</td>
                                        <td className="text-end">
                                            <button
                                                type="button"
                                                className={`btn btn-sm ${isOtherExpanded ? 'btn-primary' : 'btn-ghost-primary'} p-1`}
                                                onClick={() => handleExpandToggle(row.listingId, 'other')}
                                                title="Показать детали прочих затрат"
                                            >
                                                {formatMoney(row.otherCosts)}
                                                <i className={`ti ti-chevron-${isOtherExpanded ? 'up' : 'down'} ms-1`} />
                                            </button>
                                        </td>
                                        <td className="text-end">{formatMoney(row.totalCosts)}</td>
                                        <td className="text-end">
                                            <span className={row.profit >= 0 ? 'text-green' : 'text-red'}>
                                                {formatMoney(row.profit)}
                                            </span>
                                        </td>
                                        <td className={`text-end ${marginColor(row.marginPercent)}`}>
                                            {formatPercent(row.marginPercent)}
                                        </td>
                                        <td className={`text-end ${roiColor(row.roiPercent)}`}>
                                            {formatPercent(row.roiPercent)}
                                        </td>
                                        <td className="text-end">
                                            <button
                                                type="button"
                                                className={`btn btn-sm ${isAllExpanded ? 'btn-primary' : 'btn-ghost-secondary'} p-1`}
                                                onClick={() => handleExpandToggle(row.listingId, 'all')}
                                                title="Показать все затраты"
                                            >
                                                <i className={`ti ti-list-details`} />
                                            </button>
                                        </td>
                                    </tr>
                                    {isOtherExpanded && (
                                        <tr>
                                            <td colSpan={colCount} className="p-0 bg-light">
                                                <CostsBreakdown
                                                    groups={row.otherCostsBreakdown}
                                                    title="Прочие затраты (без комиссии и логистики)"
                                                />
                                            </td>
                                        </tr>
                                    )}
                                    {isAllExpanded && (
                                        <tr>
                                            <td colSpan={colCount} className="p-0 bg-light">
                                                <CostsBreakdown
                                                    groups={row.allCostsBreakdown}
                                                    title="Все затраты по категориям"
                                                />
                                            </td>
                                        </tr>
                                    )}
                                </React.Fragment>
                            );
                        })}
                    </tbody>
                    {totals && (
                        <tfoot>
                            <tr className="fw-bold">
                                <td className="ue-ext-frozen ue-ext-frozen-sku"></td>
                                <td className="ue-ext-frozen ue-ext-frozen-title">Итого</td>
                                <td className="text-end">{formatMoney(totals.revenue)}</td>
                                <td className="text-end">{totals.quantity.toLocaleString('ru-RU')}</td>
                                <td className="text-end text-red">{formatMoney(totals.returnsTotal)}</td>
                                <td className="text-end">{formatMoney(totals.costPriceTotal)}</td>
                                <td className="text-end">{'—'}</td>
                                <td className="text-end">{formatMoney(totals.commission)}</td>
                                <td className="text-end">{formatMoney(totals.adSpend)}</td>
                                <td className="text-end">{formatPercent(totals.drrPercent)}</td>
                                <td className="text-end">{formatMoney(totals.logistics)}</td>
                                <td className="text-end">{formatMoney(totals.otherCosts)}</td>
                                <td className="text-end">{formatMoney(totals.totalCosts)}</td>
                                <td className="text-end">
                                    <span className={totals.profit >= 0 ? 'text-green' : 'text-red'}>
                                        {formatMoney(totals.profit)}
                                    </span>
                                </td>
                                <td className={`text-end ${marginColor(totals.marginPercent)}`}>
                                    {formatPercent(totals.marginPercent)}
                                </td>
                                <td className={`text-end ${roiColor(totals.roiPercent)}`}>
                                    {formatPercent(totals.roiPercent)}
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </>
    );
};

export default UnitExtendedTable;
