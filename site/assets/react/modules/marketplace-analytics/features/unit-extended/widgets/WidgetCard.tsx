import React from 'react';
import { formatMoney } from '@/react/shared/format/number';
import { formatWidgetRevenueShare } from './widgetRevenueShare';
import type { WidgetCardConfig, WidgetsSummary } from './widgets.types';
import styles from './WidgetCard.module.css';

interface WidgetCardProps {
    config: WidgetCardConfig;
    current: WidgetsSummary;
    previous: WidgetsSummary;
    isExpanded: boolean;
    detailsId?: string;
    detailsButtonRef?: React.Ref<HTMLButtonElement>;
    allocationNote?: string;
    noteDetails?: React.ReactNode;
    onToggle: (key: string) => void;
}

/**
 * Подсчёт процента изменения current vs previous.
 * Возвращает null если предыдущий период == 0 (нельзя поделить).
 */
function calcDeltaPercent(current: number, previous: number): number | null {
    if (previous === 0) {
        return null;
    }
    return ((current - previous) / Math.abs(previous)) * 100;
}

function getBadgeColor(delta: number): 'green' | 'red' | 'secondary' {
    if (delta === 0) {
        return 'secondary';
    }
    return delta > 0 ? 'green' : 'red';
}

const WidgetCard: React.FC<WidgetCardProps> = ({
    config,
    current,
    previous,
    isExpanded,
    detailsId,
    detailsButtonRef,
    allocationNote,
    noteDetails,
    onToggle,
}) => {
    const currentValue = config.getValue(current);
    const revenueShare = formatWidgetRevenueShare(config, currentValue, current);
    const isReturns = config.key === 'returns';
    const previousValue = config.getValue(previous);
    const delta = calcDeltaPercent(currentValue, previousValue);
    const note = config.getNote?.(current) ?? null;

    return (
        <div className={isExpanded ? 'card h-100 border-primary' : 'card h-100'}>
            {config.type === 'profit' && <div className="card-status-top bg-primary" aria-hidden="true" />}
            <div className="card-body d-flex flex-column">
                <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span className="avatar avatar-sm bg-primary-lt flex-shrink-0" aria-hidden="true">
                        <i className={config.icon} />
                    </span>
                    <h3 className="card-title mb-0">{config.label}</h3>
                    {detailsId && (
                        <button
                            ref={detailsButtonRef}
                            type="button"
                            className="btn btn-sm btn-ghost-primary ms-auto"
                            aria-label={`Детали — ${config.label}`}
                            aria-expanded={isExpanded}
                            aria-controls={isExpanded ? detailsId : undefined}
                            onClick={() => onToggle(config.key)}
                        >
                            Детали
                            <i className={`ti ti-chevron-${isExpanded ? 'up' : 'down'} ms-1`} aria-hidden="true" />
                        </button>
                    )}
                </div>

                <div className={`h1 mb-2 ${styles.amount}`}>{formatMoney(currentValue)}</div>
                <div className="d-flex">
                    <span
                        className="d-inline-flex align-items-center gap-1 text-muted small"
                        title={isReturns ? 'Выручка до вычета возвратов' : 'Выручка за вычетом возвратов'}
                    >
                        <span className="badge bg-secondary-lt">{revenueShare}</span>
                        <span>{isReturns ? 'от выручки' : 'от нетто-выручки'}</span>
                    </span>
                </div>
                {allocationNote && <p className="text-muted small mt-2 mb-0">{allocationNote}</p>}
                {note && (
                    <>
                        <p className="text-muted small mt-2 mb-0">{note}</p>
                        {noteDetails}
                    </>
                )}

                <div className="mt-auto pt-3">
                    <div className="border-top pt-2">
                        <span className="d-block text-muted small mb-1">Прошлый период</span>
                        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <span className={`fw-medium ${styles.amount}`}>{formatMoney(previousValue)}</span>
                            {delta === null ? (
                                <span className="text-muted small" title="Сумма прошлого периода равна нулю">
                                    <span aria-hidden="true">—</span>
                                    <span className="visually-hidden">Изменение не рассчитано</span>
                                </span>
                            ) : (
                                <span className={`badge bg-${getBadgeColor(delta)}-lt`}>
                                    {delta > 0 ? '+' : ''}
                                    {delta.toFixed(1)}%
                                </span>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
};

export default WidgetCard;
