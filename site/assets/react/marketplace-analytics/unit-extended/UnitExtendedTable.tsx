import React, { useState, useMemo } from 'react';
import type { UnitExtendedItem, UnitExtendedTotals } from './unitExtended.types';
import CostsBreakdown from './CostsBreakdown';
import { formatMoney } from '../utils/utils';

type SortField =
    | 'title'
    | 'revenue'
    | 'quantity'
    | 'returnsTotal'
    | 'costPriceTotal'
    | 'costPriceUnit'
    | 'commission'
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

const stickyStyle: React.CSSProperties = {
    position: 'sticky',
    left: 0,
    background: 'var(--tblr-bg-surface)',
    zIndex: 1,
};

type ExpandedType = 'other' | 'all';

interface ExpandedState {
    listingId: string;
    type: ExpandedType;
}

const HEADERS: { field: SortField; label: string; align?: string }[] = [
    { field: 'title', label: 'Наименование' },
    { field: 'revenue', label: 'Выручка', align: 'text-end' },
    { field: 'quantity', label: 'Кол-во', align: 'text-end' },
    { field: 'returnsTotal', label: 'Возвраты', align: 'text-end' },
    { field: 'costPriceTotal', label: 'Себестоимость', align: 'text-end' },
    { field: 'costPriceUnit', label: 'Себест. ед.', align: 'text-end' },
    { field: 'commission', label: 'Комиссия', align: 'text-end' },
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
    if (v === null) return '\u2014';
    return `${v.toFixed(1)}%`;
}

const UnitExtendedTable: React.FC<UnitExtendedTableProps> = ({ items, totals, isLoading }) => {
    const [sortField, setSortField] = useState<SortField>('revenue');
    const [sortDir, setSortDir] = useState<SortDir>('desc');
    const [expanded, setExpanded] = useState<ExpandedState | null>(null);

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

    const colCount = HEADERS.length + 1; // +1 for "Все затраты" button column

    return (
        <div className="table-responsive">
            <table className="table table-vcenter card-table">
                <thead>
                    <tr>
                        {HEADERS.map((h) => (
                            <th
                                key={h.field}
                                className={`${h.align ?? ''} ${h.field === 'title' ? '' : ''}`}
                                style={h.field === 'title' ? { ...stickyStyle, cursor: 'pointer' } : { cursor: 'pointer' }}
                                onClick={() => handleSort(h.field)}
                            >
                                {h.label}
                                {sortField === h.field && (
                                    <i className={`ti ti-chevron-${sortDir === 'asc' ? 'up' : 'down'} ms-1`} />
                                )}
                            </th>
                        ))}
                        <th className="text-end">Все затраты</th>
                    </tr>
                </thead>
                <tbody>
                    {sorted.map((row) => {
                        const isOtherExpanded = expanded?.listingId === row.listingId && expanded?.type === 'other';
                        const isAllExpanded = expanded?.listingId === row.listingId && expanded?.type === 'all';

                        return (
                            <React.Fragment key={row.listingId}>
                                <tr>
                                    <td style={stickyStyle}>
                                        <div>{row.title || '\u2014'}</div>
                                        <div className="text-muted small">{row.sku}</div>
                                    </td>
                                    <td className="text-end">{formatMoney(row.revenue)}</td>
                                    <td className="text-end">{row.quantity.toLocaleString('ru-RU')}</td>
                                    <td className="text-end text-red">{formatMoney(row.returnsTotal)}</td>
                                    <td className="text-end">{formatMoney(row.costPriceTotal)}</td>
                                    <td className="text-end">{formatMoney(row.costPriceUnit)}</td>
                                    <td className="text-end">{formatMoney(row.commission)}</td>
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
                            <td style={stickyStyle}>Итого</td>
                            <td className="text-end">{formatMoney(totals.revenue)}</td>
                            <td className="text-end">{totals.quantity.toLocaleString('ru-RU')}</td>
                            <td className="text-end text-red">{formatMoney(totals.returnsTotal)}</td>
                            <td className="text-end">{formatMoney(totals.costPriceTotal)}</td>
                            <td className="text-end">{'\u2014'}</td>
                            <td className="text-end">{formatMoney(totals.commission)}</td>
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
    );
};

export default UnitExtendedTable;
