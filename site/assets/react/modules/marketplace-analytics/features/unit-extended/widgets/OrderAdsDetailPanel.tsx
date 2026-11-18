import React from 'react';
import { formatMoney } from '@/react/shared/format/number';
import { formatDateRu } from '@/react/shared/period/periodPresets';
import type { OrderAdAccrual, OrderAdsSummary } from './widgets.types';

interface OrderAdsDetailPanelProps {
    report: OrderAdsSummary;
    from: string;
    to: string;
}

interface CampaignAccruals {
    amount: number;
    days: OrderAdAccrual[];
}

const OrderAdsDetailPanel: React.FC<OrderAdsDetailPanelProps> = ({ report, from, to }) => {
    const campaigns = new Map<string | null, CampaignAccruals>();
    for (const accrual of report.accruals) {
        const campaign = campaigns.get(accrual.campaignId) ?? { amount: 0, days: [] };
        campaign.amount += accrual.amount;
        campaign.days.push(accrual);
        campaigns.set(accrual.campaignId, campaign);
    }

    return (
        <section className="card mb-3" aria-label="Оплата за заказ Ozon">
            <div className="card-body">
                <h3 className="card-title mb-1">Оплата за заказ Ozon</h3>
                <p className="text-secondary small">
                    Период {formatDateRu(from)} — {formatDateRu(to)}.{' '}
                    {report.lastAccrualDate
                        ? `Последнее начисление Ozon — ${formatDateRu(report.lastAccrualDate)}.`
                        : 'За выбранный период есть данные рекламной статистики. Начислений Ozon пока нет.'}
                </p>

                <dl className="row mb-3">
                    <div className="col-sm">
                        <dt>Всего в расходах</dt>
                        <dd>{formatMoney(report.linked + report.unlinked, 2)}</dd>
                    </div>
                    <div className="col-sm">
                        <dt>По товарам</dt>
                        <dd>
                            {formatMoney(report.linked, 2)}
                            {report.estimated !== 0 && (
                                <small className="d-block text-secondary">
                                    Из них распределено по продажам — {formatMoney(report.estimated, 2)}
                                </small>
                            )}
                        </dd>
                    </div>
                    <div className="col-sm">
                        <dt>Без привязки к товарам</dt>
                        <dd>{formatMoney(report.unlinked, 2)}</dd>
                    </div>
                </dl>

                {campaigns.size > 0 && (
                    <>
                        <h4 className="mb-1">Начисления по кампаниям</h4>
                        <p className="text-secondary small mb-2">
                            Источник — статья «Маркетинговые акции Ozon» в начислениях Ozon.
                        </p>
                        {Array.from(campaigns).sort((a, b) => b[1].amount - a[1].amount).map(([id, campaign]) => (
                            <details key={id ?? 'unknown'} className="border-top py-2">
                                <summary>
                                    {id ? `Кампания № ${id}` : 'Начисления без номера кампании'} · {formatMoney(campaign.amount, 2)}
                                </summary>
                                <div className="table-responsive mt-2">
                                    <table className="table table-sm table-vcenter mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Дата</th>
                                                <th scope="col" className="text-end">Начисления за вычетом сторно</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {campaign.days.map((day) => (
                                                <tr key={day.date}>
                                                    <td>{formatDateRu(day.date)}</td>
                                                    <td className="text-end">{formatMoney(day.amount, 2)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        ))}
                    </>
                )}
            </div>
        </section>
    );
};

export default OrderAdsDetailPanel;
