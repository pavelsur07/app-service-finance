import React from 'react';
import type { SnapshotSummaryTotals, SnapshotItem, MarketplaceOption, RecalculateJobResponse } from '../types/analytics.types';
import KpiCards from '../components/KpiCards';
import SnapshotsTable from '../components/SnapshotsTable';
import RecalcModal from '../components/RecalcModal';

interface MarketplaceAnalyticsViewProps {
    marketplaces: MarketplaceOption[];
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    summaryTotals: SnapshotSummaryTotals | null;
    summaryLoading: boolean;
    summaryError: string | null;
    snapshots: SnapshotItem[];
    snapshotsLoading: boolean;
    snapshotsError: string | null;
    page: number;
    pages: number;
    total: number;
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

const MarketplaceAnalyticsView: React.FC<MarketplaceAnalyticsViewProps> = (props) => {
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

            {props.summaryError && (
                <div className="alert alert-danger mb-3">{props.summaryError}</div>
            )}

            <KpiCards totals={props.summaryTotals} isLoading={props.summaryLoading} />

            <SnapshotsTable
                snapshots={props.snapshots}
                isLoading={props.snapshotsLoading}
                error={props.snapshotsError}
                page={props.page}
                pages={props.pages}
                total={props.total}
                onPageChange={props.onPageChange}
            />

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

export default MarketplaceAnalyticsView;
