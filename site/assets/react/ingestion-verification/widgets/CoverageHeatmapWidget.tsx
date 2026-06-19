import React, { useMemo, useState } from 'react';
import { useCoverageData } from '../api/ingestionVerificationApi';
import EmptyState from '../components/EmptyState';
import ErrorState from '../components/ErrorState';
import LoadingState from '../components/LoadingState';
import PeriodPicker from '../components/PeriodPicker';
import ShopSelector, { initialStoredShop } from '../components/ShopSelector';
import type { DateRangePeriod } from '../types';
import { currentMonthDateRange, useDebouncedValue } from '../utils/date';
import CoverageHeatmapView from '../views/CoverageHeatmapView';

const CoverageHeatmapWidget: React.FC = () => {
    const [period, setPeriod] = useState<DateRangePeriod>(currentMonthDateRange);
    const [shopRef, setShopRef] = useState<string | null>(initialStoredShop);

    const queryParams = useMemo(() => ({
        from: period.from,
        to: period.to,
        shopRef,
    }), [period.from, period.to, shopRef]);
    const debouncedParams = useDebouncedValue(queryParams, 500);
    const coverage = useCoverageData(debouncedParams);
    const cells = coverage.data.cells ?? [];
    const shops = coverage.data.shops ?? [];

    return (
        <div>
            <div className="card mb-3">
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                        <div className="col-md-4">
                            <ShopSelector shops={shops} value={shopRef} onChange={setShopRef} />
                        </div>
                        <div className="col-md-3">
                            <PeriodPicker
                                mode="date-range"
                                value={period}
                                onChange={setPeriod}
                                labelFrom="Дата с"
                                labelTo="Дата по"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {coverage.isError && (
                <ErrorState message={coverage.errorMessage} onRetry={coverage.reload} />
            )}

            {!coverage.isError && coverage.isLoading && <LoadingState />}

            {!coverage.isError && !coverage.isLoading && cells.length === 0 && (
                <EmptyState message="Нет загруженных ingestion-данных за выбранный период" />
            )}

            {!coverage.isError && !coverage.isLoading && cells.length > 0 && (
                <CoverageHeatmapView cells={cells} from={period.from} to={period.to} />
            )}
        </div>
    );
};

export default CoverageHeatmapWidget;
