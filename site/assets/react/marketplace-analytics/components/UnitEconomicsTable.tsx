import React from 'react';
import type { UnitEconomicsRow } from '../types/unit-economics.types';
import { formatMoney } from '../utils/utils';

interface UnitEconomicsTableProps {
    items: UnitEconomicsRow[];
    isLoading: boolean;
}

function calcProfit(row: UnitEconomicsRow): number | null {
    if (row.total_cost_price === null) return null;

    return (
        parseFloat(row.revenue)
        - parseFloat(row.refunds)
        - parseFloat(row.total_cost_price)
        - parseFloat(row.logistics_to)
        - parseFloat(row.logistics_back)
        - parseFloat(row.storage)
        - parseFloat(row.advertising_cpc)
        - parseFloat(row.advertising_other)
        - parseFloat(row.commission)
        - parseFloat(row.other_costs)
    );
}

function calcBuyoutRate(row: UnitEconomicsRow): string {
    if (row.orders_quantity === 0) return '\u2014';
    const rate = (row.delivered_quantity / row.orders_quantity) * 100;
    return rate.toFixed(1) + '%';
}

const UnitEconomicsTable: React.FC<UnitEconomicsTableProps> = ({ items, isLoading }) => {
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
                    <i className="ti ti-package text-muted" style={{ fontSize: '3rem' }}></i>
                </div>
                <p className="empty-title">Нет данных за выбранный период</p>
            </div>
        );
    }

    return (
        <div className="table-responsive">
            <table className="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th className="text-end">Выручка</th>
                        <th className="text-end">Возвраты</th>
                        <th className="text-end">Продажи, шт</th>
                        <th className="text-end">% выкупа</th>
                        <th className="text-end">Себестоимость</th>
                        <th className="text-end">Реклама</th>
                        <th className="text-end">Прибыль</th>
                        <th className="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    {items.map((row) => {
                        const profit = calcProfit(row);
                        const advertising = parseFloat(row.advertising_cpc) + parseFloat(row.advertising_other);

                        return (
                            <tr key={row.listing_id}>
                                <td>
                                    <div>{row.listing_name || '\u2014'}</div>
                                    <div className="text-muted small">{row.marketplace_sku}</div>
                                </td>
                                <td className="text-end">{formatMoney(row.revenue)}</td>
                                <td className="text-end text-red">{formatMoney(row.refunds)}</td>
                                <td className="text-end">{row.sales_quantity.toLocaleString('ru-RU')}</td>
                                <td className="text-end">{calcBuyoutRate(row)}</td>
                                <td className="text-end">
                                    {row.avg_cost_price !== null
                                        ? formatMoney(row.avg_cost_price, 2)
                                        : <span className="badge bg-muted-lt text-muted">Нет данных</span>
                                    }
                                </td>
                                <td className="text-end">{formatMoney(String(advertising))}</td>
                                <td className="text-end">
                                    {profit !== null
                                        ? <span className={profit >= 0 ? 'text-green' : 'text-red'}>{formatMoney(String(profit))}</span>
                                        : <span className="text-muted">{'\u2014'}</span>
                                    }
                                </td>
                                <td>
                                    {row.has_quality_issues && (
                                        <i className="ti ti-alert-triangle text-warning"></i>
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
};

export default UnitEconomicsTable;
