import React, { useState, useCallback } from 'react';
import { useUnitEconomics } from '../hooks/useUnitEconomics';
import { useRecalculate } from '../hooks/useRecalculate';
import { getDefaultDateRange } from '../utils/utils';
import type { MarketplaceOption } from '../types/analytics.types';
import UnitEconomicsView from '../views/UnitEconomicsView';

interface UnitEconomicsWidgetProps {
    marketplaces: MarketplaceOption[];
}

interface Filters {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
}

function getFiltersFromUrl(): Filters {
    const params = new URLSearchParams(window.location.search);
    const defaults = getDefaultDateRange();
    return {
        marketplace: params.get('marketplace') ?? '',
        dateFrom: params.get('from') ?? defaults.dateFrom,
        dateTo: params.get('to') ?? defaults.dateTo,
    };
}

function setFiltersToUrl(filters: Filters): void {
    const params = new URLSearchParams();
    if (filters.marketplace) params.set('marketplace', filters.marketplace);
    if (filters.dateFrom) params.set('from', filters.dateFrom);
    if (filters.dateTo) params.set('to', filters.dateTo);
    window.history.replaceState(null, '', window.location.pathname + '?' + params.toString());
}

const UnitEconomicsWidget: React.FC<UnitEconomicsWidgetProps> = ({
    marketplaces,
}) => {
    const [filters, setFilters] = useState<Filters>(getFiltersFromUrl);
    const [page, setPage] = useState(1);
    const [recalcModalOpen, setRecalcModalOpen] = useState(false);

    const { items, summary, meta, isLoading, isError } = useUnitEconomics({
        marketplace: filters.marketplace,
        dateFrom: filters.dateFrom,
        dateTo: filters.dateTo,
        page,
    });

    const recalc = useRecalculate();

    const handleMarketplaceChange = useCallback((mp: string) => {
        setFilters((prev) => {
            const next = { ...prev, marketplace: mp };
            setFiltersToUrl(next);
            return next;
        });
        setPage(1);
    }, []);

    const handleDateFromChange = useCallback((date: string) => {
        setFilters((prev) => {
            const next = { ...prev, dateFrom: date };
            setFiltersToUrl(next);
            return next;
        });
        setPage(1);
    }, []);

    const handleDateToChange = useCallback((date: string) => {
        setFilters((prev) => {
            const next = { ...prev, dateTo: date };
            setFiltersToUrl(next);
            return next;
        });
        setPage(1);
    }, []);

    const handleRecalculate = useCallback((mp: string, from: string, to: string) => {
        recalc.recalculate(mp, from, to);
    }, [recalc]);

    return (
        <UnitEconomicsView
            marketplaces={marketplaces}
            marketplace={filters.marketplace}
            dateFrom={filters.dateFrom}
            dateTo={filters.dateTo}
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
