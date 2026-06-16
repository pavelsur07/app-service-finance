import React, { useState, useCallback, useEffect } from 'react';
import { useUnitExtended } from './useUnitExtended';
import { useWidgets } from './widgets/useWidgets';
import WidgetsGrid from './widgets/WidgetsGrid';
import { ErrorBoundary } from '../../shared/ui/ErrorBoundary';
import { getMonthRange } from '../utils/utils';
import { getMonthPeriodKey, getPeriodKeyForRange, getPeriodRange, normalizePeriod } from '../components/PeriodPresets';
import type { PeriodKey } from '../components/PeriodPresets';
import type { MarketplaceOption } from '../types/analytics.types';
import UnitExtendedFilters from './UnitExtendedFilters';
import UnitExtendedTable from './UnitExtendedTable';
import ExportXlsButton from './ExportXlsButton';

interface UnitExtendedWidgetProps {
    marketplaces: MarketplaceOption[];
}

interface Filters {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    period: PeriodKey;
}

const UNIT_EXTENDED_WIDGET_STYLES = `
    .ue-ext-listing-search {
        width: 320px;
        max-width: 100%;
    }

    @media (max-width: 767.98px) {
        .ue-ext-listing-search {
            width: 100%;
            margin-left: 0 !important;
        }
    }
`;

function getFiltersFromUrl(): Filters {
    const params = new URLSearchParams(window.location.search);
    const currentMonth = getMonthRange(0);
    const currentMonthPeriod = getMonthPeriodKey(new Date());
    const urlDateFrom = params.get('dateFrom') ?? params.get('from');
    const urlDateTo = params.get('dateTo') ?? params.get('to');

    if (params.has('period')) {
        const requestedPeriod = normalizePeriod(params.get('period'));
        const requestedRange = getPeriodRange(requestedPeriod);

        return {
            marketplace: params.get('marketplace') ?? 'ozon',
            dateFrom: requestedPeriod === 'custom'
                ? (urlDateFrom ?? currentMonth.from)
                : (requestedRange?.from ?? currentMonth.from),
            dateTo: requestedPeriod === 'custom'
                ? (urlDateTo ?? currentMonth.to)
                : (requestedRange?.to ?? currentMonth.to),
            period: requestedRange === null && requestedPeriod !== 'custom' ? 'custom' : requestedPeriod,
        };
    }

    const hasAnyDateParam = Boolean(urlDateFrom || urlDateTo);
    const inferredPeriod = urlDateFrom && urlDateTo
        ? getPeriodKeyForRange(urlDateFrom, urlDateTo)
        : (hasAnyDateParam ? 'custom' : currentMonthPeriod);
    const inferredRange = getPeriodRange(inferredPeriod);

    return {
        marketplace: params.get('marketplace') ?? 'ozon',
        dateFrom: urlDateFrom ?? inferredRange?.from ?? currentMonth.from,
        dateTo: urlDateTo ?? inferredRange?.to ?? currentMonth.to,
        period: inferredPeriod,
    };
}

function setFiltersToUrl(filters: Filters): void {
    const params = new URLSearchParams();
    if (filters.marketplace) params.set('marketplace', filters.marketplace);
    if (filters.dateFrom) params.set('dateFrom', filters.dateFrom);
    if (filters.dateTo) params.set('dateTo', filters.dateTo);
    params.set('period', filters.period);
    const search = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : ''));
}

const UnitExtendedWidget: React.FC<UnitExtendedWidgetProps> = ({ marketplaces }) => {
    const [filters, setFilters] = useState<Filters>(getFiltersFromUrl);
    const [searchQuery, setSearchQuery] = useState('');
    const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');

    useEffect(() => {
        setFiltersToUrl(filters);
    }, [filters]);

    useEffect(() => {
        const timerId = window.setTimeout(() => {
            setDebouncedSearchQuery(searchQuery);
        }, 300);

        return () => window.clearTimeout(timerId);
    }, [searchQuery]);

    const { items, totals, isLoading, isError, errorMessage } = useUnitExtended({
        marketplace: filters.marketplace,
        periodFrom: filters.dateFrom,
        periodTo: filters.dateTo,
        search: debouncedSearchQuery,
    });

    const widgets = useWidgets({
        marketplace: filters.marketplace,
        periodFrom: filters.dateFrom,
        periodTo: filters.dateTo,
    });

    const isSearchActive = searchQuery.trim() !== '';
    const tableEmptyMessage = isSearchActive
        ? 'Ничего не найдено'
        : 'Нет данных за выбранный период';

    const handleMarketplaceChange = useCallback((mp: string) => {
        setFilters((prev) => ({ ...prev, marketplace: mp }));
    }, []);

    const handleDateFromChange = useCallback((date: string) => {
        setFilters((prev) => ({ ...prev, dateFrom: date, period: 'custom' }));
    }, []);

    const handleDateToChange = useCallback((date: string) => {
        setFilters((prev) => ({ ...prev, dateTo: date, period: 'custom' }));
    }, []);

    const handleDateRangeChange = useCallback((from: string, to: string, period: PeriodKey) => {
        setFilters((prev) => ({ ...prev, dateFrom: from, dateTo: to, period }));
    }, []);

    return (
        <>
            <style>{UNIT_EXTENDED_WIDGET_STYLES}</style>

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
                period={filters.period}
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
                <div className="card-header flex-wrap">
                    <h3 className="card-title">Юнит-экономика по листингам</h3>
                    <div className="ms-3 ue-ext-listing-search">
                        <input
                            type="search"
                            className="form-control"
                            value={searchQuery}
                            placeholder="Поиск SKU / Артикул / Наименование"
                            aria-label="Поиск по SKU, артикулу или наименованию"
                            onChange={(event) => setSearchQuery(event.target.value)}
                        />
                    </div>
                    <div className="card-options">
                        <ExportXlsButton
                            marketplace={filters.marketplace}
                            periodFrom={filters.dateFrom}
                            periodTo={filters.dateTo}
                            disabled={!filters.dateFrom || !filters.dateTo}
                        />
                        {items.length > 0 && (
                            <span className="text-muted ms-3">
                                Товаров: {items.length.toLocaleString('ru-RU')}
                            </span>
                        )}
                    </div>
                </div>

                <UnitExtendedTable
                    items={items}
                    totals={totals}
                    isLoading={isLoading}
                    emptyMessage={tableEmptyMessage}
                />
            </div>
        </>
    );
};

export default UnitExtendedWidget;
