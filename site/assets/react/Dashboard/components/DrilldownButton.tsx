import React from 'react';
import { resolveDrilldown, type DrilldownTarget } from '../utils/routing';

interface Props {
    payload: any;
    label?: string;
    onOpen: (target: DrilldownTarget) => void;
}

export function DrilldownButton({ payload, label = 'Подробнее', onOpen }: Props) {
    const { key, params } = resolveDrilldown(payload);

    if (!key) return null;

    return (
        <button
            type="button"
            className="btn btn-sm btn-outline-secondary mt-2"
            onClick={(event) => {
                event.stopPropagation();
                onOpen({ key, params });
            }}
        >
            {label}
        </button>
    );
}
