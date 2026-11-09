import { describe, expect, it } from 'vitest';
import { buildPeriodPresets, defaultPeriod, formatDateRu } from './periodPresets';

const ranges = (base: Date): Record<string, string> => Object.fromEntries(
    buildPeriodPresets(base).map((p) => [p.label, `${p.from}..${p.to}`]),
);

describe('buildPeriodPresets', () => {
    it('считает границы как Twig-пресеты ДДС (понедельник, 09.11.2026)', () => {
        expect(ranges(new Date(2026, 10, 9))).toEqual({
            'Вчера': '2026-11-08..2026-11-08',
            'Эта неделя': '2026-11-09..2026-11-09',
            'Прошлая неделя': '2026-11-02..2026-11-08',
            'Этот месяц': '2026-11-01..2026-11-09',
            'Прошлый месяц': '2026-10-01..2026-10-31',
            'Квартал': '2026-10-01..2026-11-09',
        });
    });

    it('воскресенье — конец недели, переход через год', () => {
        expect(ranges(new Date(2027, 0, 3))).toMatchObject({
            'Вчера': '2027-01-02..2027-01-02',
            'Эта неделя': '2026-12-28..2027-01-03',
            'Прошлая неделя': '2026-12-21..2026-12-27',
            'Прошлый месяц': '2026-12-01..2026-12-31',
            'Квартал': '2027-01-01..2027-01-03',
        });
    });
});

describe('formatDateRu', () => {
    it('переводит ISO в ДД.ММ.ГГГГ, прочее оставляет', () => {
        expect(formatDateRu('2026-11-09')).toBe('09.11.2026');
        expect(formatDateRu('')).toBe('');
    });
});

describe('defaultPeriod', () => {
    it('этот месяц по сегодня', () => {
        expect(defaultPeriod(new Date(2026, 10, 9))).toMatchObject({ from: '2026-11-01', to: '2026-11-09' });
    });
});
