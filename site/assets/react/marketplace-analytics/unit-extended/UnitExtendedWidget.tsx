import React, { useState, useCallback, useEffect, useMemo } from 'react';
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
import type { UnitExtendedItem, UnitExtendedTotals } from './unitExtended.types';

interface UnitExtendedWidgetProps {
    marketplaces: MarketplaceOption[];
}

interface Filters {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    period: PeriodKey;
}

type TotalSumField =
    | 'revenue'
    | 'quantity'
    | 'returnsTotal'
    | 'costPriceTotal'
    | 'commission'
    | 'adSpend'
    | 'logistics'
    | 'otherCosts'
    | 'totalCosts'
    | 'profit';

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

function normalizeSearchValue(value: string | null | undefined): string {
    return (value ?? '').trim().toLocaleLowerCase('ru-RU');
}

function matchesSearch(item: UnitExtendedItem, normalizedQuery: string): boolean {
    if (normalizedQuery === '') {
        return true;
    }

    return [
        item.sku,
        item.sellerArticle,
        item.title,
    ].some((value) => normalizeSearchValue(value).includes(normalizedQuery));
}

function sumField(items: UnitExtendedItem[], field: TotalSumField): number {
    return items.reduce((sum, item) => sum + (item[field] ?? 0), 0);
}

function buildFilteredTotals(items: UnitExtendedItem[]): UnitExtendedTotals {
    const revenue = sumField(items, 'revenue');
    const costPriceTotal = sumField(items, 'costPriceTotal');
    const adSpend = sumField(items, 'adSpend');
    const profit = sumField(items, 'profit');

    return {
        revenue,
        quantity: sumField(items, 'quantity'),
        returnsTotal: sumField(items, 'returnsTotal'),
        costPriceTotal,
        commission: sumField(items, 'commission'),
        adSpend,
        drrPercent: revenue > 0 ? (adSpend / revenue) * 100 : null,
        logistics: sumField(items, 'logistics'),
        otherCosts: sumField(items, 'otherCosts'),
        totalCosts: sumField(items, 'totalCosts'),
        profit,
        marginPercent: revenue > 0 ? (profit / revenue) * 100 : null,
        roiPercent: costPriceTotal > 0 ? (profit / costPriceTotal) * 100 : null,
    };
}

const UnitExtendedWidget: React.FC<UnitExtendedWidgetProps> = ({ marketplaces }) => {
    const [filters, setFilters] = useState<Filters>(getFiltersFromUrl);
    const [searchQuery, setSearchQuery] = useState('');

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

    const normalizedSearchQuery = useMemo(() => normalizeSearchValue(searchQuery), [searchQuery]);
    const isSearchActive = normalizedSearchQuery !== '';
    const filteredItems = useMemo(
        () => items.filter((item) => matchesSearch(item, normalizedSearchQuery)),
        [items, normalizedSearchQuery],
    );
    const visibleTotals = useMemo(
        () => (isSearchActive ? buildFilteredTotals(filteredItems) : totals),
        [filteredItems, isSearchActive, totals],
    );
    const tableEmptyMessage = isSearchActive && items.length > 0
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
                                Товаров: {filteredItems.length.toLocaleString('ru-RU')}
                                {isSearchActive && filteredItems.length !== items.length && (
                                    <> из {items.length.toLocaleString('ru-RU')}</>
                                )}
                            </span>
                        )}
                    </div>
                </div>

                <UnitExtendedTable
                    items={filteredItems}
                    totals={visibleTotals}
                    isLoading={isLoading}
                    emptyMessage={tableEmptyMessage}
                />
            </div>
        </>
    );
};

export default UnitExtendedWidget;
