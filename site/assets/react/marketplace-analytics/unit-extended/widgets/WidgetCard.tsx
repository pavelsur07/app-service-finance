import React from 'react';
import { formatMoney } from '../../utils/utils';
import type { WidgetCardConfig, WidgetsSummary, WidgetType } from './widgets.types';

interface WidgetCardProps {
    config: WidgetCardConfig;
    current: WidgetsSummary;
    previous: WidgetsSummary;
    isExpanded: boolean;
    isExpandable: boolean;
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

function getBadgeColor(type: WidgetType, delta: number): 'green' | 'red' | 'secondary' {
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
    isExpandable,
    onToggle,
}) => {
    const currentValue = config.getValue(current);
    const previousValue = config.getValue(previous);
    const delta = calcDeltaPercent(currentValue, previousValue);

    const handleClick = () => {
        if (isExpandable) {
            onToggle(config.key);
        }
    };

    const cardClass = [
        'card',
        'h-100',
        isExpandable ? 'cursor-pointer' : '',
        isExpanded ? 'border-primary' : '',
    ]
        .filter(Boolean)
        .join(' ');

    return (
        <div
            className={cardClass}
            onClick={handleClick}
            role={isExpandable ? 'button' : undefined}
            tabIndex={isExpandable ? 0 : undefined}
            onKeyDown={(e) => {
                if (isExpandable && (e.key === 'Enter' || e.key === ' ')) {
                    e.preventDefault();
                    onToggle(config.key);
                }
            }}
        >
            <div className="card-body">
                <div className="d-flex align-items-center mb-2">
                    <i className={`${config.icon} me-2 text-muted`} />
                    <div className="subheader">{config.label}</div>
                </div>

                <div className="h2 mb-1">{formatMoney(currentValue)}</div>

                <div className="d-flex align-items-center">
                    {delta === null ? (
                        <span className="text-secondary small">—</span>
                    ) : (
                        <span
                            className={`badge bg-${getBadgeColor(config.type, delta)}-lt`}
                        >
                            {delta > 0 ? '+' : ''}
                            {delta.toFixed(1)}%
                        </span>
                    )}
                    <span className="text-muted small ms-2">
                        пред: {formatMoney(previousValue)}
                    </span>
                </div>
            </div>
        </div>
    );
};

export default WidgetCard;
