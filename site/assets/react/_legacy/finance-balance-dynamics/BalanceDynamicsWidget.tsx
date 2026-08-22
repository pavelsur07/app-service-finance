import React, { useCallback, useEffect, useState } from "react";
import { useAbortableQuery } from "../shared/hooks/useAbortableQuery";
import {
    BalanceDynamicsChart,
    FLOW_SERIES,
} from "./BalanceDynamicsChart";
import {
    BALANCE_PERIODS,
    type BalanceDynamicsResponse,
    type BalanceFlowKey,
    type BalancePeriodDays,
} from "./types";

interface Props {
    endpoint: string;
    currency: string;
}

function formatPeriod(from: string, to: string): string {
    const showYear = from.slice(0, 4) !== to.slice(0, 4);
    const format = (value: string): string => {
        const [year, month, day] = value.split("-");
        return `${day}.${month}${showYear ? `.${year}` : ""}`;
    };

    return `${format(from)}–${format(to)}`;
}

function formatDays(count: number): string {
    const category = new Intl.PluralRules("ru-RU").select(count);
    const word = category === "one" ? "день" : category === "few" ? "дня" : "дней";

    return `${count} ${word}`;
}

export function BalanceDynamicsWidget({ endpoint, currency }: Props) {
    const [period, setPeriod] = useState<BalancePeriodDays>(30);
    const [visibleFlows, setVisibleFlows] = useState<ReadonlySet<BalanceFlowKey>>(new Set());
    const { data, error, isLoading, run } = useAbortableQuery<BalanceDynamicsResponse>();
    const load = useCallback(() => run({
        url: endpoint,
        query: { period, currency },
    }), [currency, endpoint, period, run]);

    useEffect(() => {
        void load();
    }, [load]);

    const toggleFlow = (flow: BalanceFlowKey) => {
        setVisibleFlows((current) => {
            const next = new Set(current);
            if (next.has(flow)) {
                next.delete(flow);
            } else {
                next.add(flow);
            }

            return next;
        });
    };

    const showLoading = isLoading || (!data && !error);
    const displayData = !showLoading && !error ? data : null;
    const breachCount = displayData?.points.filter((point) => point.below_minimum).length ?? 0;

    return (
        <section className="card vf-financial-chart" aria-labelledby="balance-dynamics-title" aria-busy={showLoading}>
            <div className="vf-financial-chart__header">
                <div>
                    <h2 className="vf-financial-chart__title" id="balance-dynamics-title">Динамика остатка на счетах</h2>
                    <p className="vf-financial-chart__subtitle">
                        Сводный остаток по всем счетам компании
                        {displayData ? ` · ${formatPeriod(displayData.period.from, displayData.period.to)}` : ""}
                    </p>
                    <div className="vf-financial-chart__legend" aria-label="Легенда графика">
                        <span><i className="is-balance" />Остаток</span>
                        {displayData?.minimum_balance && <span><i className="is-dashed is-minimum" />Мин. остаток</span>}
                    </div>
                </div>

                <div className="vf-financial-chart__periods" role="group" aria-label="Период графика">
                    {BALANCE_PERIODS.map((days) => (
                        <button
                            type="button"
                            key={days}
                            className={period === days ? "is-active" : ""}
                            aria-pressed={period === days}
                            onClick={() => setPeriod(days)}
                        >
                            {days} дн.
                        </button>
                    ))}
                </div>
            </div>

            <div className="vf-financial-chart__flow-controls" role="group" aria-label="Показать денежные потоки">
                <span className="vf-financial-chart__flow-label">Денежные потоки:</span>
                {FLOW_SERIES.map(({ key, label }) => (
                    <button
                        type="button"
                        key={key}
                        className={visibleFlows.has(key) ? "is-active" : ""}
                        aria-pressed={visibleFlows.has(key)}
                        onClick={() => toggleFlow(key)}
                    >
                        <i className={`is-${key}`} />
                        {label}
                    </button>
                ))}
            </div>

            <div className="vf-financial-chart__body">
                {showLoading && (
                    <div className="vf-financial-chart__loading" role="status">
                        <span className="spinner-border spinner-border-sm" aria-hidden="true" />
                        Загружаем динамику остатка…
                    </div>
                )}

                {!showLoading && error && (
                    <div className="vf-financial-chart__state" role="alert">
                        <strong>Не удалось загрузить график</strong>
                        <span>{error}</span>
                        <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => void load()}>
                            Повторить
                        </button>
                    </div>
                )}

                {displayData?.points.length === 0 && (
                    <div className="vf-financial-chart__state" role="status">
                        <strong>Нет данных для графика</strong>
                        <span>В выбранной валюте нет счетов с остатками за этот период.</span>
                    </div>
                )}

                {displayData && displayData.points.length > 0 && (
                    <BalanceDynamicsChart data={displayData} visibleFlows={visibleFlows} />
                )}
            </div>

            {breachCount > 0 && !showLoading && !error && (
                <div className="vf-financial-chart__warning" role="status">
                    <i className="ti ti-alert-triangle" aria-hidden="true" />
                    Остаток был ниже минимального {formatDays(breachCount)} за выбранный период.
                </div>
            )}
        </section>
    );
}
