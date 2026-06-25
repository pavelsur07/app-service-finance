import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useAdEfficiency } from './useAdEfficiency';
import AdEfficiencyTable from './AdEfficiencyTable';
import Pagination from '../../shared/components/Pagination';
import type { MarketplaceOption, SortBy, SortDir } from './adEfficiency.types';

interface AdEfficiencyPageProps {
    marketplaces: MarketplaceOption[];
}

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100];
const DEFAULT_PAGE_SIZE = 25;
const DEFAULT_SORT_BY: SortBy = 'revenue';
const DEFAULT_SORT_DIR: SortDir = 'desc';

const SORT_BY_VALUES: readonly SortBy[] = ['sku', 'title', 'revenue', 'adSpend', 'drrPercent'];

function pad2(n: number): string {
    return n < 10 ? `0${n}` : String(n);
}

function formatYmd(date: Date): string {
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

function defaultPeriod(): { periodFrom: string; periodTo: string } {
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);
    const monthAgo = new Date(yesterday);
    monthAgo.setDate(yesterday.getDate() - 30);
    return { periodFrom: formatYmd(monthAgo), periodTo: formatYmd(yesterday) };
}

function isSortBy(value: string | null): value is SortBy {
    return value !== null && (SORT_BY_VALUES as readonly string[]).includes(value);
}

function isSortDir(value: string | null): value is SortDir {
    return value === 'asc' || value === 'desc';
}

function parsePositiveInt(value: string | null, fallback: number): number {
    if (value === null) return fallback;
    const n = Number.parseInt(value, 10);
    return Number.isFinite(n) && n > 0 ? n : fallback;
}

interface State {
    marketplace: string;
    periodFrom: string;
    periodTo: string;
    page: number;
    pageSize: number;
    sortBy: SortBy;
    sortDir: SortDir;
}

function readInitialState(): State {
    const params = new URLSearchParams(window.location.search);
    const { periodFrom, periodTo } = defaultPeriod();

    const urlPageSize = parsePositiveInt(params.get('pageSize'), DEFAULT_PAGE_SIZE);
    const pageSize = PAGE_SIZE_OPTIONS.includes(urlPageSize) ? urlPageSize : DEFAULT_PAGE_SIZE;

    const urlSortBy = params.get('sortBy');
    const urlSortDir = params.get('sortDir');

    return {
        marketplace: params.get('marketplace') ?? '',
        periodFrom: params.get('periodFrom') ?? periodFrom,
        periodTo: params.get('periodTo') ?? periodTo,
        page: parsePositiveInt(params.get('page'), 1),
        pageSize,
        sortBy: isSortBy(urlSortBy) ? urlSortBy : DEFAULT_SORT_BY,
        sortDir: isSortDir(urlSortDir) ? urlSortDir : DEFAULT_SORT_DIR,
    };
}

function syncUrl(state: State): void {
    const params = new URLSearchParams(window.location.search);

    if (state.marketplace) {
        params.set('marketplace', state.marketplace);
    } else {
        params.delete('marketplace');
    }

    params.set('periodFrom', state.periodFrom);
    params.set('periodTo', state.periodTo);
    params.set('page', String(state.page));
    params.set('pageSize', String(state.pageSize));
    params.set('sortBy', state.sortBy);
    params.set('sortDir', state.sortDir);

    const search = params.toString();
    const newUrl =
        window.location.pathname + (search ? `?${search}` : '') + window.location.hash;
    window.history.replaceState(null, '', newUrl);
}

const AdEfficiencyPage: React.FC<AdEfficiencyPageProps> = ({ marketplaces }) => {
    const [state, setState] = useState<State>(readInitialState);

    useEffect(() => {
        syncUrl(state);
    }, [state]);

    const { items, total, totals, isLoading, isError, errorMessage } = useAdEfficiency({
        marketplace: state.marketplace,
        periodFrom: state.periodFrom,
        periodTo: state.periodTo,
        page: state.page,
        pageSize: state.pageSize,
        sortBy: state.sortBy,
        sortDir: state.sortDir,
    });

    const totalPages = useMemo(
        () => Math.max(1, Math.ceil(total / state.pageSize)),
        [total, state.pageSize],
    );

    useEffect(() => {
        if (isLoading || total === 0) return;
        if (state.page > totalPages) {
            setState((prev) => ({ ...prev, page: totalPages }));
        }
    }, [isLoading, total, totalPages, state.page]);

    const displayStart = total === 0 ? 0 : (state.page - 1) * state.pageSize + 1;
    const displayEnd = Math.min(state.page * state.pageSize, total);

    const handleMarketplaceChange = useCallback((e: React.ChangeEvent<HTMLSelectElement>) => {
        const value = e.target.value;
        setState((prev) => ({ ...prev, marketplace: value, page: 1 }));
    }, []);

    const handlePeriodFromChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        setState((prev) => ({ ...prev, periodFrom: value, page: 1 }));
    }, []);

    const handlePeriodToChange = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;
        setState((prev) => ({ ...prev, periodTo: value, page: 1 }));
    }, []);

    const handlePageSizeChange = useCallback((e: React.ChangeEvent<HTMLSelectElement>) => {
        const value = Number.parseInt(e.target.value, 10);
        const next = PAGE_SIZE_OPTIONS.includes(value) ? value : DEFAULT_PAGE_SIZE;
        setState((prev) => ({ ...prev, pageSize: next, page: 1 }));
    }, []);

    const handleSort = useCallback((column: SortBy) => {
        setState((prev) => {
            if (prev.sortBy === column) {
                return {
                    ...prev,
                    sortDir: prev.sortDir === 'asc' ? 'desc' : 'asc',
                    page: 1,
                };
            }
            return { ...prev, sortBy: column, sortDir: 'desc', page: 1 };
        });
    }, []);

    const handlePageChange = useCallback((page: number) => {
        setState((prev) => ({ ...prev, page }));
    }, []);

    return (
        <div className="container-fluid">
            <div className="row g-2 mb-3">
                <div className="col-md-3">
                    <label className="form-label">Маркетплейс</label>
                    <select
                        className="form-select"
                        value={state.marketplace}
                        onChange={handleMarketplaceChange}
                    >
                        <option value="">Все</option>
                        {marketplaces.map((m) => (
                            <option key={m.value} value={m.value}>
                                {m.label}
                            </option>
                        ))}
                    </select>
                </div>
                <div className="col-md-3">
                    <label className="form-label">С</label>
                    <input
                        type="date"
                        className="form-control"
                        value={state.periodFrom}
                        onChange={handlePeriodFromChange}
                    />
                </div>
                <div className="col-md-3">
                    <label className="form-label">По</label>
                    <input
                        type="date"
                        className="form-control"
                        value={state.periodTo}
                        onChange={handlePeriodToChange}
                    />
                </div>
            </div>

            {isLoading && (
                <div className="d-flex justify-content-center py-4">
                    <div className="spinner-border text-primary" role="status">
                        <span className="visually-hidden">Загрузка...</span>
                    </div>
                </div>
            )}

            {isError && (
                <div className="alert alert-danger mb-3">
                    {errorMessage ?? 'Не удалось загрузить данные'}
                </div>
            )}

            {!isLoading && !isError && (
                <>
                    <AdEfficiencyTable
                        items={items}
                        totals={totals}
                        sortBy={state.sortBy}
                        sortDir={state.sortDir}
                        onSort={handleSort}
                    />

                    <div className="row mt-3 align-items-center g-2">
                        <div className="col-md-3">
                            <div className="d-flex align-items-center gap-2">
                                <span className="text-muted">Показывать по</span>
                                <select
                                    className="form-select form-select-sm w-auto"
                                    value={state.pageSize}
                                    onChange={handlePageSizeChange}
                                >
                                    {PAGE_SIZE_OPTIONS.map((opt) => (
                                        <option key={opt} value={opt}>
                                            {opt}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="col-md-6 text-center text-muted">
                            Показано {displayStart}–{displayEnd} из {total}
                        </div>
                        <div className="col-md-3">
                            <Pagination
                                currentPage={state.page}
                                totalPages={totalPages}
                                onPageChange={handlePageChange}
                            />
                        </div>
                    </div>
                </>
            )}
        </div>
    );
};

export default AdEfficiencyPage;
