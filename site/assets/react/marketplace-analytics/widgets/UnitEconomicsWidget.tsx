import React, { useState, useCallback, useEffect } from 'react';
import { useUnitEconomics } from '../hooks/useUnitEconomics';
import { useRecalculate } from '../hooks/useRecalculate';
import { getMonthRange } from '../utils/utils';
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
    const currentMonth = getMonthRange(0);
    return {
        marketplace: params.get('marketplace') ?? '',
        dateFrom: params.get('from') ?? currentMonth.from,
        dateTo: params.get('to') ?? currentMonth.to,
    };
}

function setFiltersToUrl(filters: Filters): void {
    const params = new URLSearchParams();
    if (filters.marketplace) params.set('marketplace', filters.marketplace);
    if (filters.dateFrom) params.set('from', filters.dateFrom);
    if (filters.dateTo) params.set('to', filters.dateTo);
    const search = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : ''));
}

const UnitEconomicsWidget: React.FC<UnitEconomicsWidgetProps> = ({
    marketplaces,
}) => {
    const [filters, setFilters] = useState<Filters>(getFiltersFromUrl);
    const [page, setPage] = useState(1);
    const [recalcModalOpen, setRecalcModalOpen] = useState(false);

    useEffect(() => {
        setFiltersToUrl(filters);
    }, [filters]);

    const { items, summary, meta, isLoading, isError } = useUnitEconomics({
        marketplace: filters.marketplace,
        dateFrom: filters.dateFrom,
        dateTo: filters.dateTo,
        page,
    });

    const recalc = useRecalculate();

    const handleMarketplaceChange = useCallback((mp: string) => {
        setFilters((prev) => ({ ...prev, marketplace: mp }));
        setPage(1);
    }, []);

    const handleDateFromChange = useCallback((date: string) => {
        setFilters((prev) => ({ ...prev, dateFrom: date }));
        setPage(1);
    }, []);

    const handleDateToChange = useCallback((date: string) => {
        setFilters((prev) => ({ ...prev, dateTo: date }));
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
