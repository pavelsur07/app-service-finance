import React from 'react';
import type { UnitEconomicsRow } from '../types/unit-economics.types';
import { formatMoney } from '../utils/utils';

interface UnitEconomicsTableProps {
    items: UnitEconomicsRow[];
    isLoading: boolean;
}

function toNum(v: string | number | null | undefined): number {
    if (v === null || v === undefined) return 0;
    const n = typeof v === 'string' ? parseFloat(v) : v;
    return Number.isFinite(n) ? n : 0;
}

/**
 * «Прочие» в таблице: `other_costs` плюс все статьи, у которых нет отдельной колонки.
 * Нужно для того, чтобы сумма видимых колонок совпадала с «Итого затрат».
 */
function calcOtherCostsDisplay(row: UnitEconomicsRow): number {
    return (
        toNum(row.other_costs)
        + toNum(row.advertising_other)
        + toNum(row.advertising_external)
        + toNum(row.acquiring)
        + toNum(row.penalties)
        + toNum(row.acceptance)
    );
}

function sumCosts(row: UnitEconomicsRow): number {
    return (
        toNum(row.commission)
        + toNum(row.logistics_to)
        + toNum(row.logistics_back)
        + toNum(row.storage)
        + toNum(row.advertising_cpc)
        + toNum(row.advertising_other)
        + toNum(row.advertising_external)
        + toNum(row.acquiring)
        + toNum(row.penalties)
        + toNum(row.acceptance)
        + toNum(row.other_costs)
    );
}

function calcTotalCosts(row: UnitEconomicsRow): number | null {
    if (row.total_cost_price === null) return null;
    return toNum(row.total_cost_price) + sumCosts(row);
}

function calcProfit(row: UnitEconomicsRow): number | null {
    if (row.total_cost_price === null) return null;
    return toNum(row.revenue) - toNum(row.refunds) - (calcTotalCosts(row) ?? 0);
}

function calcMarginPercent(row: UnitEconomicsRow): number | null {
    const profit = calcProfit(row);
    if (profit === null) return null;
    const revenue = toNum(row.revenue);
    if (revenue <= 0) return null;
    return (profit / revenue) * 100;
}

function marginClass(m: number | null): string {
    if (m === null) return 'text-secondary';
    if (m >= 30) return 'text-green fw-medium';
    if (m >= 15) return 'text-azure fw-medium';
    if (m >= 5) return 'text-orange fw-medium';
    return 'text-red fw-medium';
}

function profitClass(p: number | null): string {
    if (p === null) return 'text-warning';
    return p >= 0 ? 'text-green fw-medium' : 'text-red fw-medium';
}

function formatPercent(v: number | null): string {
    if (v === null) return '\u2014';
    return `${v.toFixed(1)}%`;
}

interface Totals {
    revenue: number;
    refunds: number;
    sales_quantity: number;
    returns_quantity: number;
    total_cost_price: number | null;
    commission: number;
    logistics_to: number;
    logistics_back: number;
    storage: number;
    advertising_cpc: number;
    other_costs: number;
    total_costs: number | null;
    profit: number | null;
    margin: number | null;
}

function calcTotals(items: UnitEconomicsRow[]): Totals {
    let hasMissingCost = false;
    let costSum = 0;

    const acc = {
        revenue: 0,
        refunds: 0,
        sales_quantity: 0,
        returns_quantity: 0,
        commission: 0,
        logistics_to: 0,
        logistics_back: 0,
        storage: 0,
        advertising_cpc: 0,
        advertising_other: 0,
        advertising_external: 0,
        acquiring: 0,
        penalties: 0,
        acceptance: 0,
        other_costs: 0,
    };

    for (const row of items) {
        acc.revenue += toNum(row.revenue);
        acc.refunds += toNum(row.refunds);
        // DBAL отдаёт SUM(integer) как строку — без toNum() получим конкатенацию.
        acc.sales_quantity += toNum(row.sales_quantity);
        acc.returns_quantity += toNum(row.returns_quantity);
        acc.commission += toNum(row.commission);
        acc.logistics_to += toNum(row.logistics_to);
        acc.logistics_back += toNum(row.logistics_back);
        acc.storage += toNum(row.storage);
        acc.advertising_cpc += toNum(row.advertising_cpc);
        acc.advertising_other += toNum(row.advertising_other);
        acc.advertising_external += toNum(row.advertising_external);
        acc.acquiring += toNum(row.acquiring);
        acc.penalties += toNum(row.penalties);
        acc.acceptance += toNum(row.acceptance);
        acc.other_costs += toNum(row.other_costs);

        if (row.total_cost_price !== null) {
            costSum += toNum(row.total_cost_price);
        } else {
            hasMissingCost = true;
        }
    }

    const allCostsSumWithoutCostPrice =
        acc.commission + acc.logistics_to + acc.logistics_back + acc.storage
        + acc.advertising_cpc + acc.advertising_other + acc.advertising_external
        + acc.acquiring + acc.penalties + acc.acceptance + acc.other_costs;

    const total_costs = hasMissingCost ? null : (costSum + allCostsSumWithoutCostPrice);
    const profit = hasMissingCost ? null : (acc.revenue - acc.refunds - (total_costs ?? 0));
    const margin = (profit !== null && acc.revenue > 0) ? (profit / acc.revenue) * 100 : null;

    return {
        revenue: acc.revenue,
        refunds: acc.refunds,
        sales_quantity: acc.sales_quantity,
        returns_quantity: acc.returns_quantity,
        total_cost_price: hasMissingCost ? null : costSum,
        commission: acc.commission,
        logistics_to: acc.logistics_to,
        logistics_back: acc.logistics_back,
        storage: acc.storage,
        advertising_cpc: acc.advertising_cpc,
        // Колонка «Прочие» агрегирует все статьи без собственной колонки —
        // чтобы сумма видимых колонок совпадала с total_costs.
        other_costs: acc.other_costs + acc.advertising_other + acc.advertising_external
            + acc.acquiring + acc.penalties + acc.acceptance,
        total_costs,
        profit,
        margin,
    };
}

// Ширины колонок (px). Порядок соответствует порядку колонок в thead/tbody/tfoot.
const W = {
    name: 240,
    revenue: 130,
    salesQty: 80,
    trend: 100, // зарезервировано под спарклайн (см. §2.2 задачи)
    returnsQty: 75,
    refunds: 90,
    costTotal: 120,
    costUnit: 95,
    commission: 115,
    logisticsTo: 110,
    logisticsBack: 110,
    storage: 95,
    advertisingCpc: 100,
    otherCosts: 95,
    totalCosts: 125,
    profit: 120,
    margin: 85,
} as const;

const numColStyle = (minWidth: number): React.CSSProperties => ({
    minWidth,
    textAlign: 'right',
    fontVariantNumeric: 'tabular-nums',
});

const nameColStyle: React.CSSProperties = {
    minWidth: W.name,
    maxWidth: W.name,
};

// Inline-CSS для sticky/frozen. Pseudo-элементы и hover-состояния нельзя выразить через style=.
const TABLE_STYLES = `
.ue-scroll {
    max-height: 70vh;
    overflow: auto;
    border: 1px solid var(--tblr-border-color, rgba(0,0,0,0.1));
    border-radius: 4px;
}
.ue-table {
    border-collapse: separate;
    border-spacing: 0;
    width: max-content;
    min-width: 100%;
    margin: 0;
}
.ue-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--tblr-bg-surface);
}
.ue-table td.ue-frozen,
.ue-table th.ue-frozen {
    position: sticky;
    left: 0;
    background: var(--tblr-bg-surface);
}
.ue-table td.ue-frozen { z-index: 1; }
.ue-table thead th.ue-frozen { z-index: 3; }
.ue-table tfoot td.ue-frozen { z-index: 1; }
.ue-table .ue-frozen::after {
    content: '';
    position: absolute;
    top: 0;
    right: -4px;
    bottom: 0;
    width: 4px;
    background: linear-gradient(to right, rgba(0,0,0,0.08), transparent);
    pointer-events: none;
}
.ue-table tbody tr:hover td {
    background: var(--tblr-bg-surface-secondary, rgba(0,0,0,0.03));
}
.ue-table tbody tr:hover td.ue-frozen {
    background: var(--tblr-bg-surface-secondary, rgba(0,0,0,0.03));
}
`;

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
                    <i className="ti ti-package text-muted fs-1" />
                </div>
                <p className="empty-title">Нет данных за выбранный период</p>
            </div>
        );
    }

    const totals = calcTotals(items);

    // TODO(sort): на th добавить onClick и визуальный индикатор; состояние — в useUnitEconomics.
    // TODO(expand): рядом с `tr` держать expandedRowId и рендерить drilldown-строку по клику.

    return (
        <>
            <style>{TABLE_STYLES}</style>
            <div className="ue-scroll">
                <table className="table table-vcenter card-table ue-table">
                    <thead>
                        <tr>
                            <th className="ue-frozen" style={nameColStyle}>Наименование</th>
                            <th style={numColStyle(W.revenue)}>Выручка</th>
                            <th style={numColStyle(W.salesQty)}>Кол-во продаж</th>
                            <th style={numColStyle(W.trend)}>Тренд</th>
                            <th style={numColStyle(W.returnsQty)}>Возвраты (шт)</th>
                            <th style={numColStyle(W.refunds)}>Возвраты (₽)</th>
                            <th style={numColStyle(W.costTotal)}>Себестоимость</th>
                            <th style={numColStyle(W.costUnit)}>Себест. ед.</th>
                            <th style={numColStyle(W.commission)}>Комиссия</th>
                            <th style={numColStyle(W.logisticsTo)}>Логистика (туда)</th>
                            <th style={numColStyle(W.logisticsBack)}>Логистика (обр.)</th>
                            <th style={numColStyle(W.storage)}>Хранение</th>
                            <th style={numColStyle(W.advertisingCpc)}>Реклама (CPC)</th>
                            <th style={numColStyle(W.otherCosts)}>Прочие</th>
                            <th style={numColStyle(W.totalCosts)}>Итого затрат</th>
                            <th style={numColStyle(W.profit)}>Прибыль</th>
                            <th style={numColStyle(W.margin)}>Маржа %</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((row) => {
                            const totalCosts = calcTotalCosts(row);
                            const profit = calcProfit(row);
                            const margin = calcMarginPercent(row);
                            const costMissing = row.total_cost_price === null;

                            return (
                                <tr key={row.listing_id}>
                                    <td className="ue-frozen" style={nameColStyle}>
                                        <div className="d-flex align-items-center gap-1">
                                            <span className="text-truncate">
                                                {row.listing_name || '\u2014'}
                                            </span>
                                            {row.has_quality_issues && (
                                                <i
                                                    className="ti ti-alert-triangle text-warning ms-1"
                                                    title="Есть предупреждения о качестве данных"
                                                />
                                            )}
                                        </div>
                                        <div className="text-muted small text-truncate">
                                            {row.marketplace_sku}
                                        </div>
                                    </td>

                                    <td style={numColStyle(W.revenue)}>{formatMoney(row.revenue)}</td>
                                    <td style={numColStyle(W.salesQty)}>{row.sales_quantity}</td>
                                    <td style={numColStyle(W.trend)}>
                                        <span className="text-secondary">{'\u2014'}</span>
                                    </td>
                                    <td style={numColStyle(W.returnsQty)}>
                                        <span className={row.returns_quantity > 0 ? 'text-red' : 'text-secondary'}>
                                            {row.returns_quantity}
                                        </span>
                                    </td>
                                    <td style={numColStyle(W.refunds)}>
                                        <span className={toNum(row.refunds) > 0 ? 'text-red' : 'text-secondary'}>
                                            {formatMoney(row.refunds)}
                                        </span>
                                    </td>
                                    <td style={numColStyle(W.costTotal)}>
                                        {costMissing
                                            ? (
                                                <span
                                                    className="text-warning"
                                                    title="Себестоимость не заполнена для части дней"
                                                >
                                                    {'\u2014'}
                                                </span>
                                            )
                                            : formatMoney(row.total_cost_price)}
                                    </td>
                                    <td style={numColStyle(W.costUnit)}>
                                        {row.avg_cost_price === null
                                            ? <span className="text-warning">{'\u2014'}</span>
                                            : formatMoney(row.avg_cost_price)}
                                    </td>
                                    <td style={numColStyle(W.commission)}>{formatMoney(row.commission)}</td>
                                    <td style={numColStyle(W.logisticsTo)}>{formatMoney(row.logistics_to)}</td>
                                    <td style={numColStyle(W.logisticsBack)}>{formatMoney(row.logistics_back)}</td>
                                    <td style={numColStyle(W.storage)}>{formatMoney(row.storage)}</td>
                                    <td style={numColStyle(W.advertisingCpc)}>{formatMoney(row.advertising_cpc)}</td>
                                    <td style={numColStyle(W.otherCosts)}>{formatMoney(calcOtherCostsDisplay(row))}</td>
                                    <td style={numColStyle(W.totalCosts)}>
                                        {totalCosts === null
                                            ? <span className="text-warning" title="Нет данных о себестоимости">{'\u2014'}</span>
                                            : formatMoney(totalCosts)}
                                    </td>
                                    <td style={numColStyle(W.profit)}>
                                        <span className={profitClass(profit)}>
                                            {profit === null ? '\u2014' : formatMoney(profit)}
                                        </span>
                                    </td>
                                    <td style={numColStyle(W.margin)}>
                                        <span className={marginClass(margin)}>
                                            {formatPercent(margin)}
                                        </span>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr className="fw-bold">
                            <td className="ue-frozen" style={nameColStyle}>Итого</td>
                            <td style={numColStyle(W.revenue)}>{formatMoney(totals.revenue)}</td>
                            <td style={numColStyle(W.salesQty)}>{totals.sales_quantity}</td>
                            <td style={numColStyle(W.trend)}>
                                <span className="text-secondary">{'\u2014'}</span>
                            </td>
                            <td style={numColStyle(W.returnsQty)}>
                                <span className={totals.returns_quantity > 0 ? 'text-red' : 'text-secondary'}>
                                    {totals.returns_quantity}
                                </span>
                            </td>
                            <td style={numColStyle(W.refunds)}>
                                <span className={totals.refunds > 0 ? 'text-red' : 'text-secondary'}>
                                    {formatMoney(totals.refunds)}
                                </span>
                            </td>
                            <td style={numColStyle(W.costTotal)}>
                                {totals.total_cost_price === null
                                    ? <span className="text-warning">{'\u2014'}</span>
                                    : formatMoney(totals.total_cost_price)}
                            </td>
                            <td style={numColStyle(W.costUnit)}>{'\u2014'}</td>
                            <td style={numColStyle(W.commission)}>{formatMoney(totals.commission)}</td>
                            <td style={numColStyle(W.logisticsTo)}>{formatMoney(totals.logistics_to)}</td>
                            <td style={numColStyle(W.logisticsBack)}>{formatMoney(totals.logistics_back)}</td>
                            <td style={numColStyle(W.storage)}>{formatMoney(totals.storage)}</td>
                            <td style={numColStyle(W.advertisingCpc)}>{formatMoney(totals.advertising_cpc)}</td>
                            <td style={numColStyle(W.otherCosts)}>{formatMoney(totals.other_costs)}</td>
                            <td style={numColStyle(W.totalCosts)}>
                                {totals.total_costs === null
                                    ? <span className="text-warning">{'\u2014'}</span>
                                    : formatMoney(totals.total_costs)}
                            </td>
                            <td style={numColStyle(W.profit)}>
                                <span className={profitClass(totals.profit)}>
                                    {totals.profit === null ? '\u2014' : formatMoney(totals.profit)}
                                </span>
                            </td>
                            <td style={numColStyle(W.margin)}>
                                <span className={marginClass(totals.margin)}>
                                    {formatPercent(totals.margin)}
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </>
    );
};

export default UnitEconomicsTable;
