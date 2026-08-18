import React from 'react';
import type { TagSummaryRow } from './unitExtended.types';
import { formatMoney } from '../utils/utils';

interface TagSummaryTableProps {
    rows: TagSummaryRow[];
    isLoading: boolean;
}

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
                            <th>Тег</th>
                            <th className="text-end">Листингов</th>
                            <th className="text-end">Выручка</th>
                            <th className="text-end">Кол-во</th>
                            <th className="text-end">Себест.</th>
                            <th
                                className="text-end"
                                title="Остаток на МП FBO/FBS/RFBS (капитализация остатка)"
                            >
                                Капитал.
                            </th>
                            <th className="text-end">Реклама</th>
                            <th className="text-end">Итого затрат</th>
                            <th className="text-end">Прибыль</th>
                            <th className="text-end">Маржа %</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.tagId ?? '__untagged__'}>
                                <td>
                                    {row.tagId === null
                                        ? <span className="text-muted">{row.name}</span>
                                        : <span className="tag-chip">{row.name}</span>}
                                </td>
                                <td className="text-end">{row.listingsCount.toLocaleString('ru-RU')}</td>
                                <td className="text-end">{formatMoney(row.revenue)}</td>
                                <td className="text-end">{row.quantity.toLocaleString('ru-RU')}</td>
                                <td className="text-end">{formatMoney(row.costPriceTotal)}</td>
                                <td className="text-end">{formatMoney(row.stockCapitalRub)}</td>
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
