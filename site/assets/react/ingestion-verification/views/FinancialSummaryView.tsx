import React, { useMemo } from 'react';
import MoneyCell from '../components/MoneyCell';
import StatusBadge from '../components/StatusBadge';
import type { FinancialSummaryCategoryDto, FinancialSummaryMonthDto, MonthRangePeriod } from '../types';
import { formatMonthLabel } from '../utils/date';

interface FinancialSummaryViewProps {
    by_month: FinancialSummaryMonthDto[];
    by_category: FinancialSummaryCategoryDto[];
    period: MonthRangePeriod;
}

interface FinancialTotals {
    income: number;
    expense: number;
    net: number;
    currency: string;
}

function flowBadge(flow: string | undefined): React.ReactNode {
    if (flow === 'income') {
        return <StatusBadge status="success" label="Доход" />;
    }

    if (flow === 'expense') {
        return <StatusBadge status="error" label="Расход" />;
    }

    return <StatusBadge status="neutral" label={flow ?? '—'} />;
}

function categoryKey(item: FinancialSummaryCategoryDto): string {
    return item.category_id ?? JSON.stringify([
        'category',
        item.flow ?? null,
        item.category_name ?? null,
        item.amount_minor ?? null,
    ]);
}

const FinancialSummaryView: React.FC<FinancialSummaryViewProps> = ({
    by_month,
    by_category,
    period,
}) => {
    const totals = useMemo<FinancialTotals>(() => by_month.reduce<FinancialTotals>(
        (acc, item) => ({
            income: acc.income + (item.income_minor ?? 0),
            expense: acc.expense + (item.expense_minor ?? 0),
            net: acc.net + (item.net_minor ?? 0),
            currency: item.currency ?? acc.currency,
        }),
        { income: 0, expense: 0, net: 0, currency: 'RUB' },
    ), [by_month]);

    const categoryMonth = formatMonthLabel(period.yearTo, period.monthTo);

    return (
        <>
            <div className="row row-cards mb-3">
                <div className="col-md-4">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Доходы</div>
                            <div className="h2 mb-0">
                                <MoneyCell amountMinor={totals.income} currency={totals.currency} />
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-md-4">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Расходы</div>
                            <div className="h2 mb-0">
                                <MoneyCell amountMinor={totals.expense} currency={totals.currency} />
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-md-4">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Прибыль</div>
                            <div className="h2 mb-0">
                                <MoneyCell
                                    amountMinor={totals.net}
                                    currency={totals.currency}
                                    className={totals.net >= 0 ? 'text-success' : 'text-danger'}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="row row-cards">
                <div className="col-lg-7">
                    <div className="card">
                        <div className="card-header">
                            <h3 className="card-title">По месяцам</h3>
                        </div>
                        <div className="table-responsive">
                            <table className="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th>Месяц</th>
                                        <th className="text-end">Доход</th>
                                        <th className="text-end">Расход</th>
                                        <th className="text-end">Прибыль</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {by_month.map((item) => {
                                        const year = item.year ?? 0;
                                        const month = item.month ?? 1;
                                        const currency = item.currency ?? 'RUB';

                                        return (
                                            <tr key={`${year}-${month}`}>
                                                <td>{formatMonthLabel(year, month)}</td>
                                                <td className="text-end">
                                                    <MoneyCell amountMinor={item.income_minor} currency={currency} />
                                                </td>
                                                <td className="text-end">
                                                    <MoneyCell amountMinor={item.expense_minor} currency={currency} />
                                                </td>
                                                <td className="text-end">
                                                    <MoneyCell amountMinor={item.net_minor} currency={currency} />
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div className="col-lg-5">
                    <div className="card">
                        <div className="card-header">
                            <div>
                                <h3 className="card-title">По категориям</h3>
                                <div className="card-subtitle">{categoryMonth}</div>
                            </div>
                        </div>
                        <div className="table-responsive">
                            <table className="table table-vcenter card-table">
                                <thead>
                                    <tr>
                                        <th>Категория</th>
                                        <th>Направление</th>
                                        <th className="text-end">Сумма</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {by_category.map((item) => (
                                        <tr key={categoryKey(item)}>
                                            <td>{item.category_name ?? 'Без категории'}</td>
                                            <td>{flowBadge(item.flow)}</td>
                                            <td className="text-end">
                                                <MoneyCell amountMinor={item.amount_minor} currency={totals.currency} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
};

export default FinancialSummaryView;
