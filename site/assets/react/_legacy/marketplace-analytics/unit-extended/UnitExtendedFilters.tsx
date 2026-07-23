import React from 'react';
import PeriodPresets from '../components/PeriodPresets';
import type { PeriodKey } from '../components/PeriodPresets';
import type { MarketplaceOption } from '../types/analytics.types';
import type { ListingTag } from './unitExtended.types';

interface UnitExtendedFiltersProps {
    marketplaces: MarketplaceOption[];
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    period: PeriodKey;
    tags: ListingTag[];
    selectedTagIds: string[];
    tagsMatchAll: boolean;
    onMarketplaceChange: (mp: string) => void;
    onDateFromChange: (date: string) => void;
    onDateToChange: (date: string) => void;
    onDateRangeChange: (from: string, to: string, period: PeriodKey) => void;
    onToggleTag: (tagId: string) => void;
    onTagsMatchAllChange: (matchAll: boolean) => void;
    onClearTags: () => void;
}

const UnitExtendedFilters: React.FC<UnitExtendedFiltersProps> = ({
    marketplaces,
    marketplace,
    dateFrom,
    dateTo,
    period,
    tags,
    selectedTagIds,
    tagsMatchAll,
    onMarketplaceChange,
    onDateFromChange,
    onDateToChange,
    onDateRangeChange,
    onToggleTag,
    onTagsMatchAllChange,
    onClearTags,
}) => (
    <>
        <PeriodPresets
            onSelect={onDateRangeChange}
            currentPeriod={period}
        />

        <div className="row g-2 mb-3">
            <div className="col-auto">
                <select
                    className="form-select"
                    value={marketplace}
                    onChange={(e) => onMarketplaceChange(e.target.value)}
                >
                    {marketplaces.map((mp) => (
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
                    value={dateFrom}
                    aria-label="Дата с"
                    onChange={(e) => onDateFromChange(e.target.value)}
                />
            </div>
            <div className="col-auto">
                <input
                    type="date"
                    className="form-control"
                    value={dateTo}
                    aria-label="Дата по"
                    onChange={(e) => onDateToChange(e.target.value)}
                />
            </div>
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
