import { useEffect, useState } from 'react';
import type { DateRangePeriod, MonthPeriod, MonthRangePeriod } from '../types';

function pad2(value: number): string {
    return value < 10 ? `0${value}` : String(value);
}

export function formatYmd(date: Date): string {
    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}`;
}

export function currentMonthPeriod(): MonthPeriod {
    const today = new Date();

    return {
        year: today.getFullYear(),
        month: today.getMonth() + 1,
    };
}

export function currentMonthDateRange(): DateRangePeriod {
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);

    return {
        from: formatYmd(firstDay),
        to: formatYmd(lastDay),
    };
}

export function lastSixMonthsRange(): MonthRangePeriod {
    const current = currentMonthPeriod();
    const first = new Date(current.year, current.month - 6, 1);

    return {
        yearFrom: first.getFullYear(),
        monthFrom: first.getMonth() + 1,
        yearTo: current.year,
        monthTo: current.month,
    };
}

export function monthInputValue(period: MonthPeriod): string {
    return `${period.year}-${pad2(period.month)}`;
}

export function monthRangeStartValue(period: MonthRangePeriod): string {
    return `${period.yearFrom}-${pad2(period.monthFrom)}`;
}

export function monthRangeEndValue(period: MonthRangePeriod): string {
    return `${period.yearTo}-${pad2(period.monthTo)}`;
}

export function parseMonthInput(value: string, fallback: MonthPeriod): MonthPeriod {
    const match = /^(\d{4})-(\d{2})$/.exec(value);
    if (match === null) {
        return fallback;
    }

    const year = Number.parseInt(match[1], 10);
    const month = Number.parseInt(match[2], 10);

    if (!Number.isFinite(year) || !Number.isFinite(month) || month < 1 || month > 12) {
        return fallback;
    }

    return { year, month };
}

export function formatMonthLabel(year: number, month: number): string {
    return new Intl.DateTimeFormat('ru-RU', {
        year: 'numeric',
        month: 'long',
    }).format(new Date(year, month - 1, 1));
}

function parseYmd(value: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (match === null) {
        return null;
    }

    const year = Number.parseInt(match[1], 10);
    const month = Number.parseInt(match[2], 10);
    const day = Number.parseInt(match[3], 10);

    if (!Number.isFinite(year) || !Number.isFinite(month) || !Number.isFinite(day)) {
        return null;
    }

    return new Date(year, month - 1, day);
}

export function buildDateRange(from: string, to: string, maxDays = 370): string[] {
    const start = parseYmd(from);
    const end = parseYmd(to);

    if (start === null || end === null || start > end) {
        return [];
    }

    const dates: string[] = [];
    const cursor = new Date(start);

    while (cursor <= end && dates.length < maxDays) {
        dates.push(formatYmd(cursor));
        cursor.setDate(cursor.getDate() + 1);
    }

    return dates;
}

export function useDebouncedValue<T>(value: T, delayMs: number): T {
    const [debouncedValue, setDebouncedValue] = useState<T>(value);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setDebouncedValue(value);
        }, delayMs);

        return () => window.clearTimeout(timer);
    }, [delayMs, value]);

    return debouncedValue;
}
