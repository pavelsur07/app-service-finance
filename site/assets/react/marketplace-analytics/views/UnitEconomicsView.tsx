import React from 'react';
import type { UnitEconomicsRow, PortfolioSummary, UnitEconomicsMeta } from '../types/unit-economics.types';
import type { MarketplaceOption, RecalculateJobResponse } from '../types/analytics.types';
import KpiCards from '../components/KpiCards';
import UnitEconomicsTable from '../components/UnitEconomicsTable';
import PeriodPresets from '../components/PeriodPresets';
import RecalcModal from '../components/RecalcModal';

interface UnitEconomicsViewProps {
    marketplaces: MarketplaceOption[];
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    items: UnitEconomicsRow[];
    summary: PortfolioSummary | null;
    meta: UnitEconomicsMeta | null;
    isLoading: boolean;
    isError: boolean;
    page: number;
    recalcModalOpen: boolean;
    recalcLoading: boolean;
    recalcError: string | null;
    recalcLastJob: RecalculateJobResponse | null;
    onMarketplaceChange: (mp: string) => void;
    onDateFromChange: (date: string) => void;
    onDateToChange: (date: string) => void;
    onPageChange: (page: number) => void;
    onOpenRecalcModal: () => void;
    onCloseRecalcModal: () => void;
    onRecalculate: (marketplace: string, dateFrom: string, dateTo: string) => void;
}

const UnitEconomicsView: React.FC<UnitEconomicsViewProps> = (props) => {
    const pages = props.meta?.pages ?? 0;
    const total = props.meta?.total ?? 0;

    return (
        <>
            <div className="page-header d-print-none mb-3">
                <div className="row g-2 align-items-center">
                    <div className="col">
                        <h2 className="page-title">Аналитика маркетплейсов</h2>
                    </div>
                    <div className="col-auto ms-auto">
                        <button className="btn btn-primary" onClick={props.onOpenRecalcModal}>
                            Пересчитать
                        </button>
                    </div>
                </div>
            </div>

            <PeriodPresets
                onSelect={(from, to) => {
                    props.onDateFromChange(from);
                    props.onDateToChange(to);
                }}
                currentFrom={props.dateFrom}
                currentTo={props.dateTo}
            />

            <div className="row g-2 mb-3">
                <div className="col-auto">
                    <select
                        className="form-select"
                        value={props.marketplace}
                        onChange={(e) => props.onMarketplaceChange(e.target.value)}
                    >
                        {props.marketplaces.map((mp) => (
                            <option key={mp.value} value={mp.value}>
                                {mp.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="col-auto">
                    <input
                        type="date"
                        className="form-control"
                        value={props.dateFrom}
                        onChange={(e) => props.onDateFromChange(e.target.value)}
                    />
                </div>
                <div className="col-auto">
                    <input
                        type="date"
                        className="form-control"
                        value={props.dateTo}
                        onChange={(e) => props.onDateToChange(e.target.value)}
                    />
                </div>
            </div>

            {props.isError && (
                <div className="alert alert-danger mb-3">Не удалось загрузить данные</div>
            )}

            <KpiCards portfolio={props.summary} isLoading={props.isLoading} />

            <div className="card">
                <div className="card-header">
                    <h3 className="card-title">Юнит-экономика по товарам</h3>
                    {total > 0 && (
                        <div className="card-options">
                            <span className="text-muted">Всего: {total.toLocaleString('ru-RU')}</span>
                        </div>
                    )}
                </div>

                <UnitEconomicsTable items={props.items} isLoading={props.isLoading} />

                {pages > 1 && (
                    <div className="card-footer d-flex align-items-center">
                        <p className="m-0 text-muted">
                            Страница {props.page} из {pages}
                        </p>
                        <ul className="pagination m-0 ms-auto">
                            <li className={`page-item ${props.page <= 1 ? 'disabled' : ''}`}>
                                <button
                                    className="page-link"
                                    onClick={() => props.onPageChange(props.page - 1)}
                                    disabled={props.page <= 1}
                                >
                                    Назад
                                </button>
                            </li>
                            <li className={`page-item ${props.page >= pages ? 'disabled' : ''}`}>
                                <button
                                    className="page-link"
                                    onClick={() => props.onPageChange(props.page + 1)}
                                    disabled={props.page >= pages}
                                >
                                    Вперёд
                                </button>
                            </li>
                        </ul>
                    </div>
                )}
            </div>

            <RecalcModal
                marketplaces={props.marketplaces}
                isOpen={props.recalcModalOpen}
                isLoading={props.recalcLoading}
                error={props.recalcError}
                lastJob={props.recalcLastJob}
                onRecalculate={props.onRecalculate}
                onClose={props.onCloseRecalcModal}
            />
        </>
    );
};

export default UnitEconomicsView;
