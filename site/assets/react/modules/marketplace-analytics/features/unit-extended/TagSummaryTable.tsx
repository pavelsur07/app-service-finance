import React, { useMemo, useState } from 'react';
import type { TagSummaryRow } from './unitExtended.types';
import { sortTagSummary } from './tagSummarySort';
import type { TagSummarySortDirection, TagSummarySortField } from './tagSummarySort';
import { formatMoney } from '@/react/shared/format/number';

interface TagSummaryTableProps {
    rows: TagSummaryRow[];
    isLoading: boolean;
}

const HEADERS: { field: TagSummarySortField; label: string; tooltip?: string }[] = [
    { field: 'name', label: 'Тег' },
    { field: 'listingsCount', label: 'Листингов' },
    { field: 'revenue', label: 'Выручка' },
    { field: 'quantity', label: 'Кол-во' },
    { field: 'averageCheckRub', label: 'Сред. чек', tooltip: 'Средний чек: (выручка − возвраты) / (продано − возвращено, шт.)' },
    { field: 'costPriceTotal', label: 'Себест.' },
    { field: 'stockCapitalRub', label: 'Капитал.', tooltip: 'Остаток на МП FBO/FBS/RFBS (капитализация остатка)' },
    { field: 'adSpend', label: 'Реклама' },
    { field: 'totalCosts', label: 'Итого затрат' },
    { field: 'profit', label: 'Прибыль' },
    { field: 'marginPercent', label: 'Маржа %' },
];

function formatPercent(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}

function marginColor(value: number | null): string {
    if (value === null) return '';
    if (value < 5) return 'text-red';
    if (value <= 15) return 'text-yellow';
    return 'text-green';
}

const TagSummaryTable: React.FC<TagSummaryTableProps> = ({ rows, isLoading }) => {
    const [sortField, setSortField] = useState<TagSummarySortField>('revenue');
    const [sortDirection, setSortDirection] = useState<TagSummarySortDirection>('desc');
    const sortedRows = useMemo(
        () => sortTagSummary(rows, sortField, sortDirection),
        [rows, sortField, sortDirection],
    );

    const handleSort = (field: TagSummarySortField) => {
        if (field === sortField) {
            setSortDirection((previous) => previous === 'asc' ? 'desc' : 'asc');
        } else {
            setSortField(field);
            setSortDirection('desc');
        }
    };

    if (isLoading && rows.length === 0) {
        return (
            <div className="card-body text-center text-muted py-4">
                <span className="spinner-border spinner-border-sm me-2" role="status" />
                Загрузка…
            </div>
        );
    }

    if (rows.length === 0) {
        return (
            <div className="card-body text-muted py-4">
                Нет данных за выбранный период.
            </div>
        );
    }

    return (
        <>
            <div className="card-body py-2 text-muted small border-bottom">
                Листинг с несколькими тегами учтён в каждом теге — сумма по тегам может превышать итог.
            </div>
            <div className="table-responsive">
                <table className="table card-table table-vcenter">
                    <thead>
                        <tr>
                            {HEADERS.map((header) => (
                                <th
                                    key={header.field}
                                    className={`cursor-pointer${header.field === 'name' ? '' : ' text-end'}`}
                                    title={header.tooltip}
                                    aria-sort={sortField === header.field
                                        ? (sortDirection === 'asc' ? 'ascending' : 'descending')
                                        : 'none'}
                                    onClick={() => handleSort(header.field)}
                                >
                                    {header.label}
                                    {sortField === header.field && (
                                        <i className={`ti ti-chevron-${sortDirection === 'asc' ? 'up' : 'down'} ms-1`} />
                                    )}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {sortedRows.map((row) => (
                            <tr key={row.tagId ?? '__untagged__'}>
                                <td>
                                    {row.tagId === null
                                        ? <span className="text-muted">{row.name}</span>
                                        : <span className="tag-chip">{row.name}</span>}
                                </td>
                                <td className="text-end">{row.listingsCount.toLocaleString('ru-RU')}</td>
                                <td className="text-end">{formatMoney(row.revenue)}</td>
                                <td className="text-end">{row.quantity.toLocaleString('ru-RU')}</td>
                                <td className="text-end">{formatMoney(row.averageCheckRub)}</td>
                                <td className="text-end">{formatMoney(row.costPriceTotal)}</td>
                                <td className="text-end">
                                    {formatMoney(row.stockCapitalRub)}
                                    {row.stockCapitalUnknownCount > 0 && (
                                        <span
                                            className="text-muted ms-1"
                                            title={`Сумма неполная: у ${row.stockCapitalUnknownCount} из ${row.listingsCount} листингов стоимость остатка неизвестна (не загружен остаток либо неизвестна себестоимость единицы) и в сумму не вошла`}
                                        >
                                            *
                                        </span>
                                    )}
                                </td>
                                <td className="text-end">{formatMoney(row.adSpend)}</td>
                                <td className="text-end">{formatMoney(row.totalCosts)}</td>
                                <td className="text-end">{formatMoney(row.profit)}</td>
                                <td className={`text-end ${marginColor(row.marginPercent)}`}>
                                    {formatPercent(row.marginPercent)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    );
};

export default TagSummaryTable;
