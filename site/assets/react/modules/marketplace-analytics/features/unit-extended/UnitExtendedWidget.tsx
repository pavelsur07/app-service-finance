import React, { useState, useCallback, useEffect } from 'react';
import { useUnitExtended } from './useUnitExtended';
import { useWidgets } from './widgets/useWidgets';
import WidgetsGrid from './widgets/WidgetsGrid';
import { ErrorBoundary } from '@/react/shared/ui/ErrorBoundary';
import { defaultPeriod } from '@/react/shared/period/periodPresets';
import type { ListingTag, MarketplaceOption } from './unitExtended.types';
import UnitExtendedFilters from './UnitExtendedFilters';
import UnitExtendedTable from './UnitExtendedTable';
import TagSummaryTable from './TagSummaryTable';
import ExportXlsButton from './ExportXlsButton';
import styles from './UnitExtendedWidget.module.css';

interface UnitExtendedWidgetProps {
    marketplaces: MarketplaceOption[];
    tags?: ListingTag[];
}

interface Filters {
    marketplace: string;
    dateFrom: string;
    dateTo: string;
}

/**
 * Период — только dateFrom/dateTo в URL; активный пресет считает PeriodFilter по совпадению дат.
 * Старый параметр `period` (month_2026_11, current_month…) больше не читается: даты в старых
 * ссылках есть всегда — их писал прежний setFiltersToUrl.
 */
function getFiltersFromUrl(): Filters {
    const params = new URLSearchParams(window.location.search);
    const fallback = defaultPeriod();

    return {
        marketplace: params.get('marketplace') ?? 'ozon',
        dateFrom: params.get('dateFrom') ?? params.get('from') ?? fallback.from,
        dateTo: params.get('dateTo') ?? params.get('to') ?? fallback.to,
    };
}

function setFiltersToUrl(filters: Filters): void {
    const params = new URLSearchParams();
    if (filters.marketplace) params.set('marketplace', filters.marketplace);
    if (filters.dateFrom) params.set('dateFrom', filters.dateFrom);
    if (filters.dateTo) params.set('dateTo', filters.dateTo);
    const search = params.toString();
    window.history.replaceState(null, '', window.location.pathname + (search ? `?${search}` : ''));
}

const UnitExtendedWidget: React.FC<UnitExtendedWidgetProps> = ({ marketplaces, tags = [] }) => {
    const [filters, setFilters] = useState<Filters>(getFiltersFromUrl);
    const [searchQuery, setSearchQuery] = useState('');
    const [debouncedSearchQuery, setDebouncedSearchQuery] = useState('');
    const [selectedTagIds, setSelectedTagIds] = useState<string[]>([]);
    const [tagsMatchAll, setTagsMatchAll] = useState(false);
    const [showTagSummary, setShowTagSummary] = useState(false);

    useEffect(() => {
        setFiltersToUrl(filters);
    }, [filters]);

    useEffect(() => {
        const timerId = window.setTimeout(() => {
            setDebouncedSearchQuery(searchQuery);
        }, 300);

        return () => window.clearTimeout(timerId);
    }, [searchQuery]);

    const { items, totals, unallocated, tagSummary, isLoading, isError, errorMessage } = useUnitExtended({
        marketplace: filters.marketplace,
        periodFrom: filters.dateFrom,
        periodTo: filters.dateTo,
        search: debouncedSearchQuery,
        tagIds: selectedTagIds,
        tagsMatchAll,
        withTagSummary: showTagSummary,
    });

    const widgets = useWidgets({
        marketplace: filters.marketplace,
        periodFrom: filters.dateFrom,
        periodTo: filters.dateTo,
        tagIds: selectedTagIds,
        tagsMatchAll,
    });

    const isSearchActive = searchQuery.trim() !== '';
    const tableEmptyMessage = isSearchActive
        ? 'Ничего не найдено'
        : 'Нет данных за выбранный период';

    const handleMarketplaceChange = useCallback((mp: string) => {
        setFilters((prev) => ({ ...prev, marketplace: mp }));
    }, []);

    const handleDateRangeChange = useCallback((from: string, to: string) => {
        setFilters((prev) => ({ ...prev, dateFrom: from, dateTo: to }));
    }, []);

    const handleToggleTag = useCallback((tagId: string) => {
        setSelectedTagIds((prev) => (
            prev.includes(tagId) ? prev.filter((id) => id !== tagId) : [...prev, tagId]
        ));
    }, []);

    const handleClearTags = useCallback(() => {
        setSelectedTagIds([]);
    }, []);

    return (
        <>
            <div className="page-header d-print-none mb-3">
                <div className="row g-2 align-items-center">
                    <div className="col">
                        <h2 className="page-title">Unit — Экономика</h2>
                    </div>
                </div>
            </div>

            <UnitExtendedFilters
                marketplaces={marketplaces}
                marketplace={filters.marketplace}
                dateFrom={filters.dateFrom}
                dateTo={filters.dateTo}
                tags={tags}
                selectedTagIds={selectedTagIds}
                tagsMatchAll={tagsMatchAll}
                onMarketplaceChange={handleMarketplaceChange}
                onDateRangeChange={handleDateRangeChange}
                onToggleTag={handleToggleTag}
                onTagsMatchAllChange={setTagsMatchAll}
                onClearTags={handleClearTags}
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

            <div className="card mb-3">
                <div className="card-header">
                    <h3 className="card-title">Свод по тегам</h3>
                    <div className="card-options">
                        <label className="form-check form-switch m-0">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                checked={showTagSummary}
                                onChange={(event) => setShowTagSummary(event.target.checked)}
                            />
                            <span className="form-check-label">Показать</span>
                        </label>
                    </div>
                </div>
                {showTagSummary && (
                    <TagSummaryTable rows={tagSummary} isLoading={isLoading} />
                )}
            </div>

            <div className="card">
                <div className="card-header flex-wrap">
                    <div>
                        <h3 className="card-title">Юнит-экономика по листингам</h3>
                        <div className="text-muted small">
                            Общие затраты кабинета — строкой «Не распределено по товарам»
                            перед «Итого»; реклама — по статистике рекламного кабинета
                        </div>
                    </div>
                    <div className={`ms-3 ${styles.listingSearch}`}>
                        <input
                            type="search"
                            className="form-control"
                            value={searchQuery}
                            maxLength={255}
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
                            tagIds={selectedTagIds}
                            tagsMatchAll={tagsMatchAll}
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
                    unallocated={unallocated}
                    isLoading={isLoading}
                    emptyMessage={tableEmptyMessage}
                    selectedTagIds={selectedTagIds}
                    onToggleTag={handleToggleTag}
                />
            </div>
        </>
    );
};

export default UnitExtendedWidget;
