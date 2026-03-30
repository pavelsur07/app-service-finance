import React, { useState, useCallback } from 'react';
import { useMarketplaceSummary } from '../hooks/useMarketplaceSummary';
import { useMarketplaceSnapshots } from '../hooks/useMarketplaceSnapshots';
import { useRecalculate } from '../hooks/useRecalculate';
import { getDefaultDateRange } from '../utils/utils';
import type { MarketplaceOption } from '../types/analytics.types';
import MarketplaceAnalyticsView from '../views/MarketplaceAnalyticsView';

interface MarketplaceAnalyticsWidgetProps {
    marketplaces: MarketplaceOption[];
}

const MarketplaceAnalyticsWidget: React.FC<MarketplaceAnalyticsWidgetProps> = ({
    marketplaces,
}) => {
    const defaults = getDefaultDateRange();

    const [marketplace, setMarketplace] = useState(marketplaces[0]?.value ?? '');
    const [dateFrom, setDateFrom] = useState(defaults.dateFrom);
    const [dateTo, setDateTo] = useState(defaults.dateTo);
    const [page, setPage] = useState(1);
    const [recalcModalOpen, setRecalcModalOpen] = useState(false);

    const summary = useMarketplaceSummary({ marketplace, dateFrom, dateTo });
    const snapshotsData = useMarketplaceSnapshots({ marketplace, dateFrom, dateTo, page });
    const recalc = useRecalculate();

    const handleMarketplaceChange = useCallback((mp: string) => {
        setMarketplace(mp);
        setPage(1);
    }, []);

    const handleDateFromChange = useCallback((date: string) => {
        setDateFrom(date);
        setPage(1);
    }, []);

    const handleDateToChange = useCallback((date: string) => {
        setDateTo(date);
        setPage(1);
    }, []);

    const handleRecalculate = useCallback((mp: string, from: string, to: string) => {
        recalc.recalculate(mp, from, to);
    }, [recalc]);

    return (
        <MarketplaceAnalyticsView
            marketplaces={marketplaces}
            marketplace={marketplace}
            dateFrom={dateFrom}
            dateTo={dateTo}
            summaryTotals={summary.totals}
            summaryLoading={summary.isLoading}
            summaryError={summary.error}
            snapshots={snapshotsData.snapshots}
            snapshotsLoading={snapshotsData.isLoading}
            snapshotsError={snapshotsData.error}
            page={page}
            pages={snapshotsData.pages}
            total={snapshotsData.total}
            recalcModalOpen={recalcModalOpen}
            recalcLoading={recalc.isLoading}
            recalcError={recalc.error}
            recalcLastJob={recalc.lastJob}
            onMarketplaceChange={handleMarketplaceChange}
            onDateFromChange={handleDateFromChange}
            onDateToChange={handleDateToChange}
            onPageChange={setPage}
            onOpenRecalcModal={() => setRecalcModalOpen(true)}
            onCloseRecalcModal={() => setRecalcModalOpen(false)}
            onRecalculate={handleRecalculate}
        />
    );
};

export default MarketplaceAnalyticsWidget;
