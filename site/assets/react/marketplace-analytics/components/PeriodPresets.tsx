import React from 'react';

export type PeriodKey =
    | 'custom'
    | 'today'
    | 'yesterday'
    | 'current_week'
    | 'previous_week'
    | 'current_month'
    | 'previous_month'
    | 'current_quarter'
    | 'current_year'
    | `month_${number}_${number}`;

interface PeriodPresetsProps {
    onSelect: (from: string, to: string, period: PeriodKey) => void;
    currentPeriod?: PeriodKey;
    currentFrom?: string;
    currentTo?: string;
}

interface Preset {
    key: PeriodKey;
    label: string;
    from: string;
    to: string;
}

const MONTHS = ['Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
                'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'] as const;

function toDateStr(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function buildMonthPreset(offset: number, base: Date): Preset {
    const first = new Date(base.getFullYear(), base.getMonth() + offset, 1);
    const last = new Date(base.getFullYear(), base.getMonth() + offset + 1, 0);
    const label = offset === 0
        ? 'Текущий месяц'
        : `${MONTHS[first.getMonth()] ?? ''} ${first.getFullYear()}`;

    return {
        key: getMonthPeriodKey(first),
        label,
        from: toDateStr(first),
        to: toDateStr(last),
    };
}

export function getMonthPeriodKey(date: Date): PeriodKey {
    return `month_${date.getFullYear()}_${date.getMonth() + 1}`;
}

function buildOperationalPresets(base: Date): Preset[] {
    const today = new Date(base.getFullYear(), base.getMonth(), base.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(today.getDate() - 1);

    const currentWeekStart = new Date(today);
    const day = currentWeekStart.getDay();
    const daysFromMonday = day === 0 ? 6 : day - 1;
    currentWeekStart.setDate(today.getDate() - daysFromMonday);

    const previousWeekStart = new Date(currentWeekStart);
    previousWeekStart.setDate(currentWeekStart.getDate() - 7);
    const previousWeekEnd = new Date(currentWeekStart);
    previousWeekEnd.setDate(currentWeekStart.getDate() - 1);

    const currentMonthStart = new Date(today.getFullYear(), today.getMonth(), 1);
    const previousMonthStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
    const previousMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);

    const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;
    const currentQuarterStart = new Date(today.getFullYear(), quarterStartMonth, 1);
    const currentYearStart = new Date(today.getFullYear(), 0, 1);

    return [
        { key: 'today', label: 'Сегодня', from: toDateStr(today), to: toDateStr(today) },
        { key: 'yesterday', label: 'Вчера', from: toDateStr(yesterday), to: toDateStr(yesterday) },
        { key: 'current_week', label: 'Эта неделя', from: toDateStr(currentWeekStart), to: toDateStr(today) },
        { key: 'previous_week', label: 'Прошлая неделя', from: toDateStr(previousWeekStart), to: toDateStr(previousWeekEnd) },
        { key: 'current_month', label: 'Этот месяц', from: toDateStr(currentMonthStart), to: toDateStr(today) },
        { key: 'previous_month', label: 'Прошлый месяц', from: toDateStr(previousMonthStart), to: toDateStr(previousMonthEnd) },
        { key: 'current_quarter', label: 'Квартал', from: toDateStr(currentQuarterStart), to: toDateStr(today) },
        { key: 'current_year', label: 'Год', from: toDateStr(currentYearStart), to: toDateStr(today) },
    ];
}

export function getPeriodRange(period: PeriodKey, base = new Date()): { from: string; to: string } | null {
    if (period === 'custom') {
        return null;
    }

    const monthMatch = period.match(/^month_(\d{4})_(\d{1,2})$/);
    if (monthMatch) {
        const year = Number(monthMatch[1]);
        const month = Number(monthMatch[2]);
        if (month < 1 || month > 12) {
            return null;
        }

        const monthIndex = month - 1;
        const first = new Date(year, monthIndex, 1);
        const last = new Date(year, monthIndex + 1, 0);

        return { from: toDateStr(first), to: toDateStr(last) };
    }

    const presets = [
        ...[-4, -3, -2, -1, 0].map((offset) => buildMonthPreset(offset, base)),
        ...buildOperationalPresets(base),
    ];
    const preset = presets.find((item) => item.key === period);

    return preset ? { from: preset.from, to: preset.to } : null;
}


function parseDateStr(date: string): Date | null {
    const match = date.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    if (!match) {
        return null;
    }

    const year = Number(match[1]);
    const monthIndex = Number(match[2]) - 1;
    const day = Number(match[3]);
    const parsed = new Date(year, monthIndex, day);
    if (
        parsed.getFullYear() !== year
        || parsed.getMonth() !== monthIndex
        || parsed.getDate() !== day
    ) {
        return null;
    }

    return parsed;
}

export function getPeriodKeyForRange(from: string, to: string, base = new Date()): PeriodKey {
    const fromDate = parseDateStr(from);
    const toDate = parseDateStr(to);
    if (!fromDate || !toDate) {
        return 'custom';
    }

    const fullMonthEnd = new Date(fromDate.getFullYear(), fromDate.getMonth() + 1, 0);
    if (
        fromDate.getDate() === 1
        && toDate.getFullYear() === fromDate.getFullYear()
        && toDate.getMonth() === fromDate.getMonth()
        && toDate.getDate() === fullMonthEnd.getDate()
    ) {
        return getMonthPeriodKey(fromDate);
    }

    const preset = buildOperationalPresets(base).find((item) => item.from === from && item.to === to);

    return preset?.key ?? 'custom';
}

function isKnownPeriod(period: string | null): period is PeriodKey {
    if (period === null) {
        return false;
    }

    return period === 'custom'
        || period === 'today'
        || period === 'yesterday'
        || period === 'current_week'
        || period === 'previous_week'
        || period === 'current_month'
        || period === 'previous_month'
        || period === 'current_quarter'
        || period === 'current_year'
        || /^month_\d{4}_(?:[1-9]|1[0-2])$/.test(period);
}

export function normalizePeriod(period: string | null): PeriodKey {
    return isKnownPeriod(period) ? period : 'custom';
}

// Вычисляется один раз при загрузке модуля — не пересчитывается на каждый рендер
const TODAY = new Date();
const MONTH_PRESETS: readonly Preset[] = [-4, -3, -2, -1, 0].map((offset) =>
    buildMonthPreset(offset, TODAY),
);
const OPERATIONAL_PRESETS: readonly Preset[] = buildOperationalPresets(TODAY);

const PeriodButton: React.FC<{
    preset: Preset;
    isActive: boolean;
    onSelect: (from: string, to: string, period: PeriodKey) => void;
}> = ({ preset, isActive, onSelect }) => (
    <button
        type="button"
        aria-pressed={isActive}
        className={`btn btn-sm rounded-pill ${isActive ? 'btn-primary' : 'btn-outline-secondary'}`}
        onClick={() => onSelect(preset.from, preset.to, preset.key)}
    >
        {preset.label}
    </button>
);

const PeriodPresets: React.FC<PeriodPresetsProps> = ({
    onSelect,
    currentPeriod,
    currentFrom,
    currentTo,
}) => {
    const activePeriod = currentPeriod ?? (currentFrom && currentTo
        ? getPeriodKeyForRange(currentFrom, currentTo, TODAY)
        : 'custom');

    return (
        <div className="card mb-3">
            <div className="card-body py-3">
                <div className="text-muted text-uppercase small fw-semibold mb-2">Период</div>

                <div className="mb-3">
                    <div className="small text-muted mb-1">Месяцы:</div>
                    <div className="d-flex gap-1 flex-wrap">
                        {MONTH_PRESETS.map((preset) => (
                            <PeriodButton
                                key={preset.key}
                                preset={preset}
                                isActive={preset.key === activePeriod}
                                onSelect={onSelect}
                            />
                        ))}
                    </div>
                </div>

                <div>
                    <div className="small text-muted mb-1">Быстрый выбор:</div>
                    <div className="d-flex gap-1 flex-wrap">
                        {OPERATIONAL_PRESETS.map((preset) => (
                            <PeriodButton
                                key={preset.key}
                                preset={preset}
                                isActive={preset.key === activePeriod}
                                onSelect={onSelect}
                            />
                        ))}
                        {activePeriod === 'custom' && (
                            <button
                                type="button"
                                aria-pressed="true"
                                className="btn btn-sm rounded-pill btn-primary"
                                disabled
                            >
                                Произвольный
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
};

export default PeriodPresets;
