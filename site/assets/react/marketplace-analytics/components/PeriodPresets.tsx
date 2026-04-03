import React from 'react';
import { getMonthRange } from '../utils/utils';

interface PeriodPresetsProps {
    onSelect: (from: string, to: string) => void;
    currentFrom: string;
    currentTo: string;
}

const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
                'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'] as const;

function getMonthLabel(offset: number): string {
    const today = new Date();
    const d = new Date(today.getFullYear(), today.getMonth() + offset, 1);
    return `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

interface Preset {
    label: string;
    from: string;
    to: string;
}

const PeriodPresets: React.FC<PeriodPresetsProps> = ({ onSelect, currentFrom, currentTo }) => {
    const presets: Preset[] = [
        { label: getMonthLabel(-4), ...getMonthRange(-4) },
        { label: getMonthLabel(-3), ...getMonthRange(-3) },
        { label: getMonthLabel(-2), ...getMonthRange(-2) },
        { label: getMonthLabel(-1), ...getMonthRange(-1) },
        { label: 'Текущий месяц', ...getMonthRange(0) },
    ];

    return (
        <div className="d-flex gap-1 flex-wrap mb-2">
            {presets.map((preset) => (
                <button
                    key={preset.label}
                    type="button"
                    className={`btn btn-sm ${
                        preset.from === currentFrom && preset.to === currentTo
                            ? 'btn-primary'
                            : 'btn-outline-secondary'
                    }`}
                    onClick={() => onSelect(preset.from, preset.to)}
                >
                    {preset.label}
                </button>
            ))}
        </div>
    );
};

export default PeriodPresets;
