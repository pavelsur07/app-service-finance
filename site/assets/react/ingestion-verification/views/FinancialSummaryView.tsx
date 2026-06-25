import React, { useMemo } from 'react';
import MoneyCell from '../components/MoneyCell';
import StatusBadge from '../components/StatusBadge';
import type {
    FinancialSummaryCategoryDto,
    FinancialSummaryMarketplaceCategoryDto,
    FinancialSummaryMonthDto,
    MonthRangePeriod,
} from '../types';
import { formatMonthLabel } from '../utils/date';

interface FinancialSummaryViewProps {
    by_month: FinancialSummaryMonthDto[];
    by_category: FinancialSummaryCategoryDto[];
    marketplace_categories: FinancialSummaryMarketplaceCategoryDto[];
    period: MonthRangePeriod;
}

interface FinancialTotals {
    income: number;
    expense: number;
    net: number;
    currency: string;
}

interface MarketplaceCategoryGroup {
    group: string;
    items: FinancialSummaryMarketplaceCategoryDto[];
}

const TYPE_LABELS: Record<string, string> = {
    sale: 'Продажа',
    refund: 'Возврат',
    commission: 'Комиссия',
    logistics: 'Логистика',
    storage: 'Хранение',
    last_mile: 'Последняя миля',
    acceptance: 'Приемка',
    advertising: 'Реклама',
    penalty: 'Штраф',
    bonus: 'Бонус',
    acquiring: 'Эквайринг',
    adjustment: 'Корректировка',
    payout: 'Выплата',
    deposit: 'Поступление',
    transfer: 'Перевод',
    tax: 'Налог',
    fee: 'Сбор',
    other: 'Прочее',
};

function flowBadge(flow: string | undefined): React.ReactNode {
    if (flow === 'income') {
        return <StatusBadge status="success" label="Доход" />;
    }

    if (flow === 'expense') {
        return <StatusBadge status="error" label="Расход" />;
    }

    return <StatusBadge status="neutral" label={flow ?? '—'} />;
}

function directionBadge(direction: string | undefined): React.ReactNode {
    if (direction === 'in') {
        return <StatusBadge status="success" label="Вход" />;
    }

    if (direction === 'out') {
        return <StatusBadge status="error" label="Выход" />;
    }

    return <StatusBadge status="neutral" label={direction ?? '—'} />;
}

function categoryKey(item: FinancialSummaryCategoryDto): string {
    return item.category_id ?? JSON.stringify([
        'category',
        item.flow ?? null,
        item.category_name ?? null,
        item.amount_minor ?? null,
    ]);
}

function marketplaceCategoryKey(item: FinancialSummaryMarketplaceCategoryDto): string {
    return JSON.stringify([
        item.source ?? null,
        item.category_group ?? null,
        item.category_name ?? null,
        item.type ?? null,
        item.direction ?? null,
    ]);
}

const FinancialSummaryView: React.FC<FinancialSummaryViewProps> = ({
    by_month,
    by_category,
    marketplace_categories,
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

    const marketplaceGroups = useMemo<MarketplaceCategoryGroup[]>(() => {
        const groupMap = new Map<string, MarketplaceCategoryGroup>();

        marketplace_categories.forEach((item) => {
            const group = item.category_group ?? 'Без группы Ozon';
            const existing = groupMap.get(group);

            if (existing !== undefined) {
                existing.items.push(item);
                return;
            }

            groupMap.set(group, { group, items: [item] });
        });

        return Array.from(groupMap.values());
    }, [marketplace_categories]);

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

            <div className="card mt-3">
                <div className="card-header">
                    <div>
                        <h3 className="card-title">Категории Ozon</h3>
                        <div className="card-subtitle">{categoryMonth}</div>
                    </div>
                </div>
                {marketplaceGroups.length === 0 ? (
                    <div className="card-body text-secondary">
                        Нет данных Ozon за выбранный месяц
                    </div>
                ) : (
                    <div className="table-responsive">
                        <table className="table table-vcenter card-table">
                            <thead>
                                <tr>
                                    <th>Статья</th>
                                    <th>Тип</th>
                                    <th>Направление</th>
                                    <th className="text-end">Операций</th>
                                    <th className="text-end">Сумма</th>
                                </tr>
                            </thead>
                            <tbody>
                                {marketplaceGroups.map((group) => (
                                    <React.Fragment key={group.group}>
                                        <tr className="table-light">
                                            <td colSpan={5}>
                                                <strong>{group.group}</strong>
                                            </td>
                                        </tr>
                                        {group.items.map((item) => {
                                            const amount = item.amount_minor ?? 0;
                                            const type = item.type ?? 'other';

                                            return (
                                                <tr key={marketplaceCategoryKey(item)}>
                                                    <td>{item.category_name ?? 'Без категории'}</td>
                                                    <td>{TYPE_LABELS[type] ?? type}</td>
                                                    <td>{directionBadge(item.direction)}</td>
                                                    <td className="text-end">{item.tx_count ?? 0}</td>
                                                    <td className="text-end">
                                                        <MoneyCell
                                                            amountMinor={amount}
                                                            currency={totals.currency}
                                                            className={amount >= 0 ? 'text-success' : 'text-danger'}
                                                        />
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </React.Fragment>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
};

export default FinancialSummaryView;
