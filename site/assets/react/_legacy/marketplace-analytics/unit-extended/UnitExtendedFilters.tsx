import React from 'react';
import { PeriodFilter } from '../../../shared/period/PeriodFilter';
import type { MarketplaceOption } from '../types/analytics.types';
import type { ListingTag } from './unitExtended.types';

interface UnitExtendedFiltersProps {
    marketplaces: MarketplaceOption[];
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    tags: ListingTag[];
    selectedTagIds: string[];
    tagsMatchAll: boolean;
    onMarketplaceChange: (mp: string) => void;
    onDateRangeChange: (from: string, to: string) => void;
    onToggleTag: (tagId: string) => void;
    onTagsMatchAllChange: (matchAll: boolean) => void;
    onClearTags: () => void;
}

const UnitExtendedFilters: React.FC<UnitExtendedFiltersProps> = ({
    marketplaces,
    marketplace,
    dateFrom,
    dateTo,
    tags,
    selectedTagIds,
    tagsMatchAll,
    onMarketplaceChange,
    onDateRangeChange,
    onToggleTag,
    onTagsMatchAllChange,
    onClearTags,
}) => (
    <>
        {/* Период — как фильтр «Транзакции ДДС»: пресеты DS и одно окно «Дата с / по». */}
        <div className="d-flex flex-wrap align-items-center gap-3 mb-3">
            <select
                className="form-select w-auto"
                value={marketplace}
                aria-label="Маркетплейс"
                onChange={(e) => onMarketplaceChange(e.target.value)}
            >
                {marketplaces.map((mp) => (
                    <option key={mp.value} value={mp.value}>
                        {mp.label}
                    </option>
                ))}
            </select>
            <PeriodFilter from={dateFrom} to={dateTo} onChange={onDateRangeChange} />
        </div>

        {tags.length > 0 && (
            <div className="d-flex align-items-center gap-2 flex-wrap mb-3">
                <span className="text-muted small">Теги:</span>
                {tags.map((tag) => {
                    const isActive = selectedTagIds.includes(tag.id);
                    return (
                        <button
                            key={tag.id}
                            type="button"
                            className={`btn btn-sm ${isActive ? 'btn-primary' : 'btn-outline-secondary'}`}
                            aria-pressed={isActive}
                            onClick={() => onToggleTag(tag.id)}
                        >
                            {tag.name}
                        </button>
                    );
                })}
                {selectedTagIds.length > 1 && (
                    <div className="btn-group btn-group-sm ms-1" role="group" aria-label="Совпадение тегов">
                        <button
                            type="button"
                            className={`btn ${tagsMatchAll ? 'btn-outline-secondary' : 'btn-secondary'}`}
                            aria-pressed={!tagsMatchAll}
                            onClick={() => onTagsMatchAllChange(false)}
                        >
                            Любой
                        </button>
                        <button
                            type="button"
                            className={`btn ${tagsMatchAll ? 'btn-secondary' : 'btn-outline-secondary'}`}
                            aria-pressed={tagsMatchAll}
                            onClick={() => onTagsMatchAllChange(true)}
                        >
                            Все
                        </button>
                    </div>
                )}
                {selectedTagIds.length > 0 && (
                    <button
                        type="button"
                        className="btn btn-sm btn-link text-muted"
                        onClick={onClearTags}
                    >
                        Сбросить
                    </button>
                )}
            </div>
        )}
    </>
);

export default UnitExtendedFilters;
