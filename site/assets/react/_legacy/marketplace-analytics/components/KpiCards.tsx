import React from 'react';
import type { SnapshotSummaryTotals } from '../types/analytics.types';
import type { PortfolioSummary } from '../types/unit-economics.types';
import { formatMoney } from '../utils/utils';

interface KpiCardsProps {
    totals?: SnapshotSummaryTotals | null;
    portfolio?: PortfolioSummary | null;
    isLoading: boolean;
}

const KpiCards: React.FC<KpiCardsProps> = ({ totals, portfolio, isLoading }) => {
    if (isLoading) {
        return (
            <div className="row row-deck row-cards mb-3">
                {[1, 2, 3, 4].map((i) => (
                    <div key={i} className="col-sm-6 col-lg-3">
                        <div className="card card-body placeholder-glow">
                            <p className="placeholder col-5 mb-2" />
                            <p className="placeholder col-8" />
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    if (portfolio) {
        return (
            <div className="row row-deck row-cards mb-3">
                <div className="col-sm-6 col-lg-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Выручка</div>
                            <div className="h1 mb-0">{formatMoney(portfolio.total_revenue)}</div>
                        </div>
                    </div>
                </div>
                <div className="col-sm-6 col-lg-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Возвраты</div>
                            <div className="h1 mb-0">{formatMoney(portfolio.total_refunds)}</div>
                        </div>
                    </div>
                </div>
                <div className="col-sm-6 col-lg-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Продажи, шт</div>
                            <div className="h1 mb-0">{portfolio.total_sales_quantity.toLocaleString('ru-RU')}</div>
                        </div>
                    </div>
                </div>
                <div className="col-sm-6 col-lg-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Прибыль</div>
                            <div className="h1 mb-0">
                                {portfolio.total_profit !== null
                                    ? formatMoney(portfolio.total_profit)
                                    : <span className="text-muted" title="Заполните себестоимость">{'\u2014'}</span>
                                }
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    }

    if (!totals) return null;

    return (
        <div className="row row-deck row-cards mb-3">
            <div className="col-sm-6 col-lg-3">
                <div className="card">
                    <div className="card-body">
                        <div className="subheader">Выручка</div>
                        <div className="h1 mb-0">{formatMoney(totals.revenue)}</div>
                    </div>
                </div>
            </div>
            <div className="col-sm-6 col-lg-3">
                <div className="card">
                    <div className="card-body">
                        <div className="subheader">Возвраты</div>
                        <div className="h1 mb-0">{formatMoney(totals.refunds)}</div>
                    </div>
                </div>
            </div>
            <div className="col-sm-6 col-lg-3">
                <div className="card">
                    <div className="card-body">
                        <div className="subheader">Продажи, шт</div>
                        <div className="h1 mb-0">{totals.sales_quantity.toLocaleString('ru-RU')}</div>
                    </div>
                </div>
            </div>
            <div className="col-sm-6 col-lg-3">
                <div className="card">
                    <div className="card-body">
                        <div className="subheader">Заказы, шт</div>
                        <div className="h1 mb-0">{totals.orders_quantity.toLocaleString('ru-RU')}</div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default KpiCards;
