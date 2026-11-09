/**
 * Пресеты периода DS «Ваш Финдир» — тот же набор и те же границы, что
 * `templates/partials/_period_presets.html.twig` (фильтр «Транзакции ДДС»):
 * «Этот …» и «Квартал» — от начала периода по сегодня.
 */
export interface PeriodPreset {
    label: string;
    from: string;
    to: string;
}

export function toDateStr(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** `2026-11-09` → `09.11.2026`; пустое и некорректное — как есть. */
export function formatDateRu(date: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);

    return match ? `${match[3]}.${match[2]}.${match[1]}` : date;
}

export function buildPeriodPresets(base: Date = new Date()): PeriodPreset[] {
    const today = new Date(base.getFullYear(), base.getMonth(), base.getDate());
    const day = (offset: number): Date => new Date(today.getFullYear(), today.getMonth(), today.getDate() + offset);

    const daysFromMonday = (today.getDay() + 6) % 7;
    const weekStart = day(-daysFromMonday);
    const quarterStartMonth = Math.floor(today.getMonth() / 3) * 3;

    return [
        { label: 'Вчера', from: toDateStr(day(-1)), to: toDateStr(day(-1)) },
        { label: 'Эта неделя', from: toDateStr(weekStart), to: toDateStr(today) },
        { label: 'Прошлая неделя', from: toDateStr(day(-daysFromMonday - 7)), to: toDateStr(day(-daysFromMonday - 1)) },
        { label: 'Этот месяц', from: toDateStr(new Date(today.getFullYear(), today.getMonth(), 1)), to: toDateStr(today) },
        {
            label: 'Прошлый месяц',
            from: toDateStr(new Date(today.getFullYear(), today.getMonth() - 1, 1)),
            to: toDateStr(new Date(today.getFullYear(), today.getMonth(), 0)),
        },
        { label: 'Квартал', from: toDateStr(new Date(today.getFullYear(), quarterStartMonth, 1)), to: toDateStr(today) },
    ];
}

/** Период по умолчанию — «Этот месяц» (с 1-го по сегодня), как пресет. */
export function defaultPeriod(base: Date = new Date()): PeriodPreset {
    const preset = buildPeriodPresets(base).find((p) => p.label === 'Этот месяц');
    if (preset === undefined) {
        throw new Error('Пресет «Этот месяц» не найден');
    }

    return preset;
}
