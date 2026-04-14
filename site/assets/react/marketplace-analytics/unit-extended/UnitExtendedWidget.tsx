import React, { useState, useCallback, useEffect } from 'react';
import { useUnitExtended } from './useUnitExtended';
import { useWidgets } from './widgets/useWidgets';
import WidgetsGrid from './widgets/WidgetsGrid';
import { ErrorBoundary } from '../../shared/ui/ErrorBoundary';
import { getMonthRange } from '../utils/utils';
import type { MarketplaceOption } from '../types/analytics.types';
import UnitExtendedFilters from './UnitExtendedFilters';
import UnitExtendedTable from './UnitExtendedTable';

interface UnitExtendedWidgetProps {
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
        marketplace: params.get('marketplace') ?? 'ozon',
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

const UnitExtendedWidget: React.FC<UnitExtendedWidgetProps> = ({ marketplaces }) => {
    const [filters, setFilters] = useState<Filters>(getFiltersFromUrl);

    useEffect(() => {
        setFiltersToUrl(filters);
    }, [filters]);

    const { items, totals, isLoading, isError, errorMessage } = useUnitExtended({
        marketplace: filters.marketplace,
        periodFrom: filters.dateFrom,
        periodTo: filters.dateTo,
    });

    const widgets = useWidgets({
        marketplace: filters.marketplace,
        periodFrom: filters.dateFrom,
        periodTo: filters.dateTo,
    });

    const handleMarketplaceChange = useCallback((mp: string) => {
        setFilters((prev) => ({ ...prev, marketplace: mp }));
    }, []);

    const handleDateFromChange = useCallback((date: string) => {
        setFilters((prev) => ({ ...prev, dateFrom: date }));
    }, []);

    const handleDateToChange = useCallback((date: string) => {
        setFilters((prev) => ({ ...prev, dateTo: date }));
    }, []);

    const handleDateRangeChange = useCallback((from: string, to: string) => {
        setFilters((prev) => ({ ...prev, dateFrom: from, dateTo: to }));
    }, []);

    return (
        <>
            <div className="page-header d-print-none mb-3">
                <div className="row g-2 align-items-center">
                    <div className="col">
                        <h2 className="page-title">Unit — расширенный</h2>
                    </div>
                </div>
            </div>

            <UnitExtendedFilters
                marketplaces={marketplaces}
                marketplace={filters.marketplace}
                dateFrom={filters.dateFrom}
                dateTo={filters.dateTo}
                onMarketplaceChange={handleMarketplaceChange}
                onDateFromChange={handleDateFromChange}
                onDateToChange={handleDateToChange}
                onDateRangeChange={handleDateRangeChange}
            />

            <ErrorBoundary widgetName="UnitEconomyWidgets">
                <WidgetsGrid
                    summary={widgets.summary}
                    isLoading={widgets.isLoading}
                    error={widgets.error}
                    expandedKey={widgets.expandedKey}
                    expandedGroups={widgets.expandedGroups}
                    onToggle={widgets.toggleWidget}
                />
            </ErrorBoundary>

            {isError && (
                <div className="alert alert-danger mb-3">
                    {errorMessage ?? 'Не удалось загрузить данные'}
                </div>
            )}

            <div className="card">
                <div className="card-header">
                    <h3 className="card-title">Юнит-экономика по листингам</h3>
                    {items.length > 0 && (
                        <div className="card-options">
                            <span className="text-muted">
                                Товаров: {items.length.toLocaleString('ru-RU')}
                            </span>
                        </div>
                    )}
                </div>

                <UnitExtendedTable
                    items={items}
                    totals={totals}
                    isLoading={isLoading}
                />
            </div>
        </>
    );
};

export default UnitExtendedWidget;
