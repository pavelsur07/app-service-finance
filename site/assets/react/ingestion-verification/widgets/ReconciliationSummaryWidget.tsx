import React, { useMemo, useState } from 'react';
import { useReconciliationData, useShopOptions } from '../api/ingestionVerificationApi';
import EmptyState from '../components/EmptyState';
import ErrorState from '../components/ErrorState';
import LoadingState from '../components/LoadingState';
import PeriodPicker from '../components/PeriodPicker';
import ShopSelector, { initialStoredShop } from '../components/ShopSelector';
import type { MonthPeriod } from '../types';
import { currentMonthPeriod, useDebouncedValue } from '../utils/date';
import ReconciliationSummaryView from '../views/ReconciliationSummaryView';

const ReconciliationSummaryWidget: React.FC = () => {
    const [period, setPeriod] = useState<MonthPeriod>(currentMonthPeriod);
    const [shopRef, setShopRef] = useState<string | null>(initialStoredShop);
    const shopOptions = useShopOptions();
    const shops = shopOptions.data.shops ?? [];

    const queryParams = useMemo(() => ({
        shopRef,
        year: period.year,
        month: period.month,
    }), [period.month, period.year, shopRef]);
    const debouncedParams = useDebouncedValue(queryParams, 500);
    const canLoadReconciliation = shops.length > 0 && shopRef !== null;
    const reconciliation = useReconciliationData(debouncedParams, canLoadReconciliation);

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
                                includeAll={false}
                                disabled={shopOptions.isLoading}
                            />
                        </div>
                        <div className="col-md-3">
                            <PeriodPicker mode="month" value={period} onChange={setPeriod} />
                        </div>
                    </div>
                </div>
            </div>

            {shopOptions.isError && (
                <ErrorState message={shopOptions.errorMessage} onRetry={shopOptions.reload} />
            )}

            {!shopOptions.isError && shopOptions.isLoading && shops.length === 0 && (
                <LoadingState message="Загрузка списка магазинов..." />
            )}

            {!shopOptions.isError && !shopOptions.isLoading && shops.length === 0 && (
                <EmptyState
                    title="Магазины не найдены"
                    message="Для сверки нужен магазин с ingestion-загрузками за последние 90 дней"
                />
            )}

            {!shopOptions.isError && !shopOptions.isLoading && shops.length > 0 && shopRef === null && (
                <EmptyState
                    title="Выберите магазин"
                    message="Сверка выполняется по конкретному магазину"
                />
            )}

            {!shopOptions.isError && shops.length > 0 && shopRef !== null && reconciliation.isError && (
                <ErrorState message={reconciliation.errorMessage} onRetry={reconciliation.reload} />
            )}

            {!shopOptions.isError && shops.length > 0 && shopRef !== null && !reconciliation.isError && reconciliation.isLoading && (
                <LoadingState />
            )}

            {!shopOptions.isError
                && shops.length > 0
                && shopRef !== null
                && !reconciliation.isError
                && !reconciliation.isLoading
                && reconciliation.data.summary === undefined && (
                <EmptyState message="Нет данных сверки за выбранный период" />
            )}

            {!shopOptions.isError
                && shops.length > 0
                && shopRef !== null
                && !reconciliation.isError
                && !reconciliation.isLoading
                && reconciliation.data.summary !== undefined && (
                <ReconciliationSummaryView
                    summary={reconciliation.data.summary}
                    by_type={reconciliation.data.by_type ?? []}
                    year={period.year}
                    month={period.month}
                />
            )}
        </div>
    );
};

export default ReconciliationSummaryWidget;
