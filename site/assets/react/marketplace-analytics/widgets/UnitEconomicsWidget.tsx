import React, { useState, useCallback } from 'react';
import { useUnitEconomics } from '../hooks/useUnitEconomics';
import { useRecalculate } from '../hooks/useRecalculate';
import { getDefaultDateRange } from '../utils/utils';
import UnitEconomicsView from '../views/UnitEconomicsView';

interface UnitEconomicsWidgetProps {
    defaultMarketplace?: string;
}

const UnitEconomicsWidget: React.FC<UnitEconomicsWidgetProps> = ({
    defaultMarketplace = 'wildberries',
}) => {
    const defaults = getDefaultDateRange();

    const [marketplace, setMarketplace] = useState(defaultMarketplace);
    const [dateFrom, setDateFrom] = useState(defaults.dateFrom);
    const [dateTo, setDateTo] = useState(defaults.dateTo);
    const [page, setPage] = useState(1);
    const [recalcModalOpen, setRecalcModalOpen] = useState(false);

    const { items, summary, meta, isLoading, isError } = useUnitEconomics({
        marketplace,
        dateFrom,
        dateTo,
        page,
    });

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
        <UnitEconomicsView
            marketplace={marketplace}
            dateFrom={dateFrom}
            dateTo={dateTo}
            items={items}
            summary={summary}
            meta={meta}
            isLoading={isLoading}
            isError={isError}
            page={page}
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

export default UnitEconomicsWidget;
