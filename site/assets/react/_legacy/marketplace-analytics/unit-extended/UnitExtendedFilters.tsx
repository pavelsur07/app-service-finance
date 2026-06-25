import React from 'react';
import PeriodPresets from '../components/PeriodPresets';
import type { PeriodKey } from '../components/PeriodPresets';
import type { MarketplaceOption } from '../types/analytics.types';

interface UnitExtendedFiltersProps {
    marketplaces: MarketplaceOption[];
    marketplace: string;
    dateFrom: string;
    dateTo: string;
    period: PeriodKey;
    onMarketplaceChange: (mp: string) => void;
    onDateFromChange: (date: string) => void;
    onDateToChange: (date: string) => void;
    onDateRangeChange: (from: string, to: string, period: PeriodKey) => void;
}

const UnitExtendedFilters: React.FC<UnitExtendedFiltersProps> = ({
    marketplaces,
    marketplace,
    dateFrom,
    dateTo,
    period,
    onMarketplaceChange,
    onDateFromChange,
    onDateToChange,
    onDateRangeChange,
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
    </>
);

export default UnitExtendedFilters;
