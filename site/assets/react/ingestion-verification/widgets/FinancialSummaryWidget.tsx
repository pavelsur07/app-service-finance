import React, { useMemo, useState } from 'react';
import { useFinancialSummaryData, useShopOptions } from '../api/ingestionVerificationApi';
import EmptyState from '../components/EmptyState';
import ErrorState from '../components/ErrorState';
import LoadingState from '../components/LoadingState';
import PeriodPicker from '../components/PeriodPicker';
import ShopSelector, { initialStoredShop } from '../components/ShopSelector';
import type { MonthRangePeriod } from '../types';
import { lastSixMonthsRange, useDebouncedValue } from '../utils/date';
import FinancialSummaryView from '../views/FinancialSummaryView';

const FinancialSummaryWidget: React.FC = () => {
    const [period, setPeriod] = useState<MonthRangePeriod>(lastSixMonthsRange);
    const [shopRef, setShopRef] = useState<string | null>(initialStoredShop);
    const shopOptions = useShopOptions();
    const shops = shopOptions.data.shops ?? [];

    const queryParams = useMemo(() => ({
        shopRef,
        yearFrom: period.yearFrom,
        monthFrom: period.monthFrom,
        yearTo: period.yearTo,
        monthTo: period.monthTo,
    }), [period.monthFrom, period.monthTo, period.yearFrom, period.yearTo, shopRef]);
    const debouncedParams = useDebouncedValue(queryParams, 500);
    const summary = useFinancialSummaryData(debouncedParams);
    const by_month = summary.data.by_month ?? [];
    const by_category = summary.data.by_category ?? [];
    const marketplace_categories = summary.data.marketplace_categories ?? [];
    const hasSummary = by_month.length > 0 || by_category.length > 0 || marketplace_categories.length > 0;

    return (
        <div>
            <div className="card mb-3">
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                        <div className="col-md-4">
                            <ShopSelector
                                shops={shops}
                                value={shopRef}
                                onChange={setShopRef}
                                disabled={shopOptions.isLoading}
                            />
                        </div>
                        <div className="col-md-3">
                            <PeriodPicker mode="month-range" value={period} onChange={setPeriod} />
                        </div>
                    </div>
                </div>
            </div>

            {shopOptions.isError && (
                <ErrorState message={shopOptions.errorMessage} onRetry={shopOptions.reload} />
            )}

            {!shopOptions.isError && summary.isError && (
                <ErrorState message={summary.errorMessage} onRetry={summary.reload} />
            )}

            {!shopOptions.isError && !summary.isError && summary.isLoading && <LoadingState />}

            {!shopOptions.isError && !summary.isError && !summary.isLoading && !hasSummary && (
                <EmptyState message="Финансовой сводки за выбранный период нет" />
            )}

            {!shopOptions.isError && !summary.isError && !summary.isLoading && hasSummary && (
                <FinancialSummaryView
                    by_month={by_month}
                    by_category={by_category}
                    marketplace_categories={marketplace_categories}
                    period={period}
                />
            )}
        </div>
    );
};

export default FinancialSummaryWidget;
