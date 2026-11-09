import { describe, expect, it } from 'vitest';
import { formatMoney, formatQty } from './number';

describe('formatMoney', () => {
    it('рубли без копеек по умолчанию, строка и число', () => {
        expect(formatMoney(1234.5)).toBe('1 235 ₽');
        expect(formatMoney('-10', 2)).toBe('-10,00 ₽');
    });
    it('неизвестное — прочерк', () => {
        expect(formatMoney(null)).toBe('—');
        expect(formatMoney('abc')).toBe('—');
    });
});

describe('formatQty', () => {
    it('дробь до 3 знаков, null — прочерк', () => {
        expect(formatQty(1.23456)).toBe('1,235');
        expect(formatQty(undefined)).toBe('—');
    });
});
