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
        - parseFloat(row.advertising_external)
        - parseFloat(row.commission)
        - parseFloat(row.acquiring)
        - parseFloat(row.penalties)
        - parseFloat(row.acceptance)
        - parseFloat(row.other_costs)
    );
}

interface Totals {
    revenue: number;
    refunds: number;
    total_cost_price: number | null;
    commission: number;
    logistics: number;
    storage: number;
    advertising: number;
    acquiring: number;
    acceptance: number;
    penalties: number;
    other_costs: number;
    profit: number | null;
}

function calcTotals(items: UnitEconomicsRow[]): Totals {
    let hasMissingData = false;
    let costSum = 0;

    let revenue = 0;
    let refunds = 0;
    let commission = 0;
    let logistics = 0;
    let storage = 0;
    let advertising = 0;
    let acquiring = 0;
    let acceptance = 0;
    let penalties = 0;
    let other_costs = 0;

    for (const row of items) {
        revenue += parseFloat(row.revenue);
        refunds += parseFloat(row.refunds);
        commission += parseFloat(row.commission);
        logistics += parseFloat(row.logistics_to) + parseFloat(row.logistics_back);
        storage += parseFloat(row.storage);
        advertising += parseFloat(row.advertising_cpc) + parseFloat(row.advertising_other) + parseFloat(row.advertising_external);
        acquiring += parseFloat(row.acquiring);
        acceptance += parseFloat(row.acceptance);
        penalties += parseFloat(row.penalties);
        other_costs += parseFloat(row.other_costs);

        if (row.total_cost_price !== null) {
            costSum += parseFloat(row.total_cost_price);
        } else {
            hasMissingData = true;
        }
    }

    const profitSum = revenue - refunds - costSum - commission - logistics - storage - advertising - acquiring - acceptance - penalties - other_costs;

    return {
        revenue,
        refunds,
        total_cost_price: hasMissingData ? null : costSum,
        commission,
        logistics,
        storage,
        advertising,
        acquiring,
        acceptance,
        penalties,
        other_costs,
        profit: hasMissingData ? null : profitSum,
    };
}

const stickyStyle: React.CSSProperties = {
    position: 'sticky',
    left: 0,
    background: 'var(--tblr-bg-surface)',
    zIndex: 1,
};

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
                    <i className="ti ti-package text-muted fs-1"></i>
                </div>
                <p className="empty-title">Нет данных за выбранный период</p>
            </div>
        );
    }

    const totals = calcTotals(items);

    return (
        <div className="table-responsive">
            <table className="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th style={stickyStyle}>Товар</th>
                        <th className="text-end">Выручка</th>
                        <th className="text-end">Возвраты</th>
                        <th className="text-end">Себест. итого</th>
                        <th className="text-end">Комиссия МП</th>
                        <th className="text-end">Логистика</th>
                        <th className="text-end">Хранение</th>
                        <th className="text-end">Реклама</th>
                        <th className="text-end">Эквайринг</th>
                        <th className="text-end">Приёмка</th>
                        <th className="text-end">Штрафы</th>
                        <th className="text-end">Прочие</th>
                        <th className="text-end">Прибыль</th>
                        <th className="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    {items.map((row) => {
                        const profit = calcProfit(row);
                        const logistics = parseFloat(row.logistics_to) + parseFloat(row.logistics_back);
                        const advertising = parseFloat(row.advertising_cpc) + parseFloat(row.advertising_other) + parseFloat(row.advertising_external);

                        return (
                            <tr key={row.listing_id}>
                                <td style={stickyStyle}>
                                    <div>{row.listing_name || '\u2014'}</div>
                                    <div className="text-muted small">{row.marketplace_sku}</div>
                                </td>
                                <td className="text-end">{formatMoney(row.revenue)}</td>
                                <td className="text-end text-red">{formatMoney(row.refunds)}</td>
                                <td className="text-end">
                                    {row.total_cost_price !== null
                                        ? formatMoney(row.total_cost_price)
                                        : <span className="badge bg-muted-lt text-muted">Нет данных</span>
                                    }
                                </td>
                                <td className="text-end">{formatMoney(row.commission)}</td>
                                <td className="text-end">{formatMoney(logistics)}</td>
                                <td className="text-end">{formatMoney(row.storage)}</td>
                                <td className="text-end">{formatMoney(advertising)}</td>
                                <td className="text-end">{formatMoney(row.acquiring)}</td>
                                <td className="text-end">{formatMoney(row.acceptance)}</td>
                                <td className="text-end">{formatMoney(row.penalties)}</td>
                                <td className="text-end">{formatMoney(row.other_costs)}</td>
                                <td className="text-end">
                                    {profit !== null
                                        ? <span className={profit >= 0 ? 'text-green' : 'text-red'}>{formatMoney(profit)}</span>
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
                <tfoot>
                    <tr className="fw-bold">
                        <td style={stickyStyle}>Итого</td>
                        <td className="text-end">{formatMoney(totals.revenue)}</td>
                        <td className="text-end text-red">{formatMoney(totals.refunds)}</td>
                        <td className="text-end">
                            {totals.total_cost_price !== null
                                ? formatMoney(totals.total_cost_price)
                                : <span className="text-muted">{'\u2014'}</span>
                            }
                        </td>
                        <td className="text-end">{formatMoney(totals.commission)}</td>
                        <td className="text-end">{formatMoney(totals.logistics)}</td>
                        <td className="text-end">{formatMoney(totals.storage)}</td>
                        <td className="text-end">{formatMoney(totals.advertising)}</td>
                        <td className="text-end">{formatMoney(totals.acquiring)}</td>
                        <td className="text-end">{formatMoney(totals.acceptance)}</td>
                        <td className="text-end">{formatMoney(totals.penalties)}</td>
                        <td className="text-end">{formatMoney(totals.other_costs)}</td>
                        <td className="text-end">
                            {totals.profit !== null
                                ? <span className={totals.profit >= 0 ? 'text-green' : 'text-red'}>{formatMoney(totals.profit)}</span>
                                : <span className="text-muted">{'\u2014'}</span>
                            }
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    );
};

export default UnitEconomicsTable;
