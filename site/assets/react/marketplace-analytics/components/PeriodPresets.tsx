import React from 'react';

interface PeriodPresetsProps {
    onSelect: (from: string, to: string) => void;
    currentFrom: string;
    currentTo: string;
}

interface Preset {
    label: string;
    from: string;
    to: string;
}

const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
                'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'] as const;

function toDateStr(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function buildPreset(offset: number, base: Date): Preset {
    const first = new Date(base.getFullYear(), base.getMonth() + offset, 1);
    const last = new Date(base.getFullYear(), base.getMonth() + offset + 1, 0);
    const label = offset === 0
        ? 'Текущий месяц'
        : `${MONTHS[first.getMonth()] ?? ''} ${first.getFullYear()}`;
    return { label, from: toDateStr(first), to: toDateStr(last) };
}

// Вычисляется один раз при загрузке модуля — не пересчитывается на каждый рендер
const TODAY = new Date();
const PRESETS: readonly Preset[] = [-4, -3, -2, -1, 0].map((offset) =>
    buildPreset(offset, TODAY),
);

const PeriodPresets: React.FC<PeriodPresetsProps> = ({ onSelect, currentFrom, currentTo }) => (
    <div className="d-flex gap-1 flex-wrap mb-2">
        {PRESETS.map((preset) => {
            const isActive = preset.from === currentFrom && preset.to === currentTo;
            return (
                <button
                    key={preset.label}
                    type="button"
                    aria-pressed={isActive}
                    className={`btn btn-sm ${isActive ? 'btn-primary' : 'btn-outline-secondary'}`}
                    onClick={() => onSelect(preset.from, preset.to)}
                >
                    {preset.label}
                </button>
            );
        })}
    </div>
);

export default PeriodPresets;
