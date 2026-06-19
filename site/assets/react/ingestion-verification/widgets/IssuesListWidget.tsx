import React, { useMemo, useState } from 'react';
import { useIssuesData, useShopOptions } from '../api/ingestionVerificationApi';
import EmptyState from '../components/EmptyState';
import ErrorState from '../components/ErrorState';
import LoadingState from '../components/LoadingState';
import PeriodPicker from '../components/PeriodPicker';
import ShopSelector, { initialStoredShop } from '../components/ShopSelector';
import type { MonthPeriod } from '../types';
import { currentMonthPeriod, useDebouncedValue } from '../utils/date';
import IssuesListView from '../views/IssuesListView';

const PAGE_LIMIT = 50;

const IssuesListWidget: React.FC = () => {
    const [period, setPeriod] = useState<MonthPeriod>(currentMonthPeriod);
    const [shopRef, setShopRef] = useState<string | null>(initialStoredShop);
    const [page, setPage] = useState(1);
    const shopOptions = useShopOptions();
    const shops = shopOptions.data.shops ?? [];

    const queryParams = useMemo(() => ({
        shopRef,
        year: period.year,
        month: period.month,
        page,
        limit: PAGE_LIMIT,
    }), [page, period.month, period.year, shopRef]);
    const debouncedParams = useDebouncedValue(queryParams, 500);
    const issues = useIssuesData(debouncedParams);
    const items = issues.data.items ?? [];

    const handleShopChange = (nextShopRef: string | null): void => {
        setShopRef(nextShopRef);
        setPage(1);
    };

    const handlePeriodChange = (nextPeriod: MonthPeriod): void => {
        setPeriod(nextPeriod);
        setPage(1);
    };

    return (
        <div>
            <div className="card mb-3">
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                        <div className="col-md-4">
                            <ShopSelector
                                shops={shops}
                                value={shopRef}
                                onChange={handleShopChange}
                                disabled={shopOptions.isLoading}
                            />
                        </div>
                        <div className="col-md-3">
                            <PeriodPicker mode="month" value={period} onChange={handlePeriodChange} />
                        </div>
                    </div>
                </div>
            </div>

            {shopOptions.isError && (
                <ErrorState message={shopOptions.errorMessage} onRetry={shopOptions.reload} />
            )}

            {!shopOptions.isError && issues.isError && (
                <ErrorState message={issues.errorMessage} onRetry={issues.reload} />
            )}

            {!shopOptions.isError && !issues.isError && issues.isLoading && <LoadingState />}

            {!shopOptions.isError && !issues.isError && !issues.isLoading && items.length === 0 && (
                <EmptyState message="Открытых проблем за выбранный период нет" />
            )}

            {!shopOptions.isError && !issues.isError && !issues.isLoading && items.length > 0 && (
                <IssuesListView
                    items={items}
                    meta={issues.data.meta ?? { page, limit: PAGE_LIMIT, total: items.length, total_pages: 1 }}
                    year={period.year}
                    month={period.month}
                    onPageChange={setPage}
                />
            )}
        </div>
    );
};

export default IssuesListWidget;
