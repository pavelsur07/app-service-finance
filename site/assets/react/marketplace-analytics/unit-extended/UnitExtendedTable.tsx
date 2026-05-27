import React, { useState, useMemo } from 'react';
import type { UnitExtendedItem, UnitExtendedTotals } from './unitExtended.types';
import CostsBreakdown from './CostsBreakdown';
import { formatMoney } from '../utils/utils';
import './UnitExtendedTable.css';

type SortField =
    | 'sku'
    | 'title'
    | 'sellerArticle'
    | 'revenue'
    | 'quantity'
    | 'returnsTotal'
    | 'costPriceTotal'
    | 'costPriceUnit'
    | 'stockQty'
    | 'stockCapitalRub'
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

type ExpandedType = 'other' | 'all';

interface ExpandedState {
    listingId: string;
    type: ExpandedType;
}

const HEADERS: { field: SortField; label: string; align?: string; tooltip?: string }[] = [
    { field: 'sku', label: 'SKU' },
    { field: 'title', label: 'Наименование' },
    { field: 'sellerArticle', label: 'Артикул' },
    { field: 'revenue', label: 'Выручка', align: 'text-end' },
    { field: 'quantity', label: 'Кол-во', align: 'text-end' },
    { field: 'returnsTotal', label: 'Возвраты', align: 'text-end' },
    { field: 'costPriceTotal', label: 'Себестоимость', align: 'text-end' },
    { field: 'costPriceUnit', label: 'Себест. ед.', align: 'text-end' },
    { field: 'stockQty', label: 'Ост. шт.', align: 'text-end' },
    { field: 'stockCapitalRub', label: 'Кап. р.', align: 'text-end' },
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
    if (field === 'sellerArticle') {
        return (a.sellerArticle ?? '').localeCompare(b.sellerArticle ?? '', 'ru');
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

    // Renders sortable header row.
    const renderHeaderCells = () => {

        const frozenClass = (field: SortField): string => {
            if (field === 'sku') return 'ue-ext-frozen ue-ext-frozen-sku';
            if (field === 'title') return 'ue-ext-frozen ue-ext-frozen-title';
            return '';
        };

        return (
            <tr>
                {HEADERS.map((h) => {
                    return (
                        <th
                            key={h.field}
                            className={`${h.align ?? ''} ${frozenClass(h.field)}`}
                            style={{
                                cursor: 'pointer',
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
                <th className="text-end">
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
            <div className="ue-ext-scroll">
                <table className="table table-vcenter card-table ue-ext-table">
                    <thead>
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
                                        <td>
                                            <div className="text-truncate">{row.sellerArticle || '—'}</div>
                                        </td>
                                        <td className="text-end">{formatMoney(row.revenue)}</td>
                                        <td className="text-end">{row.quantity.toLocaleString('ru-RU')}</td>
                                        <td className="text-end text-red">{formatMoney(row.returnsTotal)}</td>
                                        <td className="text-end">{formatMoney(row.costPriceTotal)}</td>
                                        <td className="text-end">{formatMoney(row.costPriceUnit)}</td>
                                        <td className="text-end">{row.stockQty.toLocaleString('ru-RU', { minimumFractionDigits: 0, maximumFractionDigits: 3 })}</td>
                                        <td className="text-end">{formatMoney(row.stockCapitalRub)}</td>
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
                                <td></td>
                                <td className="text-end">{formatMoney(totals.revenue)}</td>
                                <td className="text-end">{totals.quantity.toLocaleString('ru-RU')}</td>
                                <td className="text-end text-red">{formatMoney(totals.returnsTotal)}</td>
                                <td className="text-end">{formatMoney(totals.costPriceTotal)}</td>
                                <td className="text-end">{'—'}</td>
                                <td className="text-end">{'—'}</td>
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
