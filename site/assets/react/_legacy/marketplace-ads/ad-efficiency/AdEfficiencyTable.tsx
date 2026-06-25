import React from 'react';
import type {
    AdEfficiencyItem,
    AdEfficiencyTotals,
    SortBy,
    SortDir,
} from './adEfficiency.types';
import { formatDrr, formatRub } from './formatters';

interface AdEfficiencyTableProps {
    items: AdEfficiencyItem[];
    totals: AdEfficiencyTotals | null;
    sortBy: SortBy;
    sortDir: SortDir;
    onSort: (column: SortBy) => void;
}

interface SortableHeaderProps {
    column: SortBy;
    sortBy: SortBy;
    sortDir: SortDir;
    onSort: (column: SortBy) => void;
    children: React.ReactNode;
}

const SortableHeader: React.FC<SortableHeaderProps> = ({
    column,
    sortBy,
    sortDir,
    onSort,
    children,
}) => {
    const isActive = sortBy === column;
    const arrow = isActive ? (sortDir === 'asc' ? ' ↑' : ' ↓') : '';

    return (
        <button
            type="button"
            className="btn btn-link p-0 text-reset text-decoration-none fw-semibold"
            onClick={() => onSort(column)}
        >
            {children}
            {arrow && <span className="ms-1">{arrow}</span>}
        </button>
    );
};

const AdEfficiencyTable: React.FC<AdEfficiencyTableProps> = ({
    items,
    totals,
    sortBy,
    sortDir,
    onSort,
}) => {
    return (
        <div className="card">
            <div className="table-responsive">
                <table className="table table-vcenter table-hover card-table">
                    <thead>
                        <tr>
                            <th>
                                <SortableHeader
                                    column="sku"
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                >
                                    SKU
                                </SortableHeader>
                            </th>
                            <th>
                                <SortableHeader
                                    column="title"
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                >
                                    Название
                                </SortableHeader>
                            </th>
                            <th className="text-end">
                                <SortableHeader
                                    column="revenue"
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                >
                                    Выручка, ₽
                                </SortableHeader>
                            </th>
                            <th className="text-end">
                                <SortableHeader
                                    column="adSpend"
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                >
                                    Расходы на рекламу, ₽
                                </SortableHeader>
                            </th>
                            <th className="text-end">
                                <SortableHeader
                                    column="drrPercent"
                                    sortBy={sortBy}
                                    sortDir={sortDir}
                                    onSort={onSort}
                                >
                                    ДРР, %
                                </SortableHeader>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="text-center py-5 text-muted">
                                    Нет данных за выбранный период
                                </td>
                            </tr>
                        ) : (
                            items.map((item) => (
                                <tr key={item.listingId}>
                                    <td>{item.sku}</td>
                                    <td>{item.title ?? '—'}</td>
                                    <td className="text-end">{formatRub(item.revenue)}</td>
                                    <td className="text-end">{formatRub(item.adSpend)}</td>
                                    <td className="text-end">{formatDrr(item.drrPercent)}</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                    {totals && (
                        <tfoot>
                            <tr className="fw-bold">
                                <td colSpan={2}>Итого</td>
                                <td className="text-end">{formatRub(totals.revenue)}</td>
                                <td className="text-end">{formatRub(totals.adSpend)}</td>
                                <td className="text-end">{formatDrr(totals.drrPercent)}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
        </div>
    );
};

export default AdEfficiencyTable;
