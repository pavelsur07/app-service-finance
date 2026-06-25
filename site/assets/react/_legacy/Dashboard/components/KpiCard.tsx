import React from 'react';
import { formatAmount } from '../utils/formatters';
import { resolveDrilldown, MapsToDrilldown } from '../utils/routing';

export function KpiCard({ title, value, meta, payload }: any) {
    const { key, params } = resolveDrilldown(payload);
    const isClickable = Boolean(key);

    const handleAction = () => isClickable && MapsToDrilldown({ key, params });

    return (
        <div className="col-sm-6 col-lg-3">
            <div
                className={`card ${isClickable ? 'cursor-pointer' : ''}`}
                onClick={handleAction}
            >
                <div className="card-body">
                    <div className="subheader">{title}</div>
                    <div className="h2 mb-1">{formatAmount(value)}</div>
                    <div className="text-muted small">{meta}</div>
                </div>
            </div>
        </div>
    );
}
