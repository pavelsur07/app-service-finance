import { describe, expect, it } from 'vitest';
import { addAmounts, formatPercent, formatValue, inputToStored, isZero, negate, parseAmountInput, storedToInput } from './amount';

const NBSP = ' ';

describe('parseAmountInput', () => {
    it.each([
        ['600000', '600000.00'],
        ['600 000', '600000.00'],
        [`600${NBSP}000,5`, '600000.50'],
        ['600000.05', '600000.05'],
        ['−1 200', '-1200.00'],
        ['-0', '0.00'],
        ['007', '7.00'],
        ['', '0.00'],
        ['   ', '0.00'],
        ['1234567890123456', '1234567890123456.00'],
    ])('«%s» → %s', (input, expected) => {
        expect(parseAmountInput(input)).toBe(expected);
    });

    it.each(['abc', '1,234', '1.2.3', '12345678901234567', '1e5', '--1', '1-'])('«%s» — не сумма', (input) => {
        // «1,234» — три знака после запятой, не разделитель разрядов.
        expect(parseAmountInput(input)).toBeNull();
    });
});

describe('знак расходной статьи', () => {
    it('расход вводится положительным, хранится с минусом', () => {
        expect(inputToStored('600.00', 'expense')).toBe('-600.00');
        expect(inputToStored('-600.00', 'expense')).toBe('600.00');
        expect(inputToStored('600.00', 'income')).toBe('600.00');
        expect(inputToStored('600.00', 'none')).toBe('600.00');
    });

    it('в поле редактирования — обратно, без нулевых копеек, с запятой', () => {
        expect(storedToInput('-600.00', 'expense')).toBe('600');
        expect(storedToInput('-600.50', 'expense')).toBe('600,50');
        expect(storedToInput('600.50', 'income')).toBe('600,50');
        expect(storedToInput('0.00', 'expense')).toBe('');
    });

    it('ноль без знака', () => {
        expect(negate('0.00')).toBe('0.00');
        expect(negate('-0.00')).toBe('0.00');
        expect(isZero('-0.00')).toBe(true);
        expect(isZero('0.01')).toBe(false);
    });
});

describe('addAmounts', () => {
    it('складывает копейками без потери точности', () => {
        expect(addAmounts(['0.10', '0.20'])).toBe('0.30');
        expect(addAmounts(['9999999999999999.99', '0.01'])).toBe('10000000000000000.00');
    });

    it('знак и пустой набор', () => {
        expect(addAmounts(['-100.50', '40.25'])).toBe('-60.25');
        expect(addAmounts(['-0.50', '0.50'])).toBe('0.00');
        expect(addAmounts([])).toBe('0.00');
        expect(addAmounts(['5', '-0.5'])).toBe('4.50');
    });
});

describe('formatValue', () => {
    it('деньги — без копеек, разряды NBSP, минус U+2212', () => {
        expect(formatValue('-1234567.49', 'MONEY')).toEqual({ whole: `−1${NBSP}234${NBSP}567`, minor: null, zero: false });
    });

    it('ноль после округления — без минуса и помечен', () => {
        expect(formatValue('-0.4', 'MONEY')).toEqual({ whole: '0', minor: null, zero: true });
    });

    it('показатели — по формату', () => {
        expect(formatValue('12.345', 'PERCENT')).toEqual({ whole: '12', minor: `,3${NBSP}%`, zero: false });
        expect(formatValue('1.5', 'RATIO')).toEqual({ whole: '1', minor: ',5000', zero: false });
        expect(formatValue('3', 'QTY')).toEqual({ whole: '3', minor: ',00', zero: false });
    });

    it('не число — ноль', () => {
        expect(formatValue('x', 'MONEY').zero).toBe(true);
    });
});

describe('formatPercent', () => {
    it.each([
        ['12.50', `12,5${NBSP}%`],
        ['100.00', `100${NBSP}%`],
        ['7', `7${NBSP}%`],
        ['0.25', `0,25${NBSP}%`],
    ])('%s → %s', (value, expected) => {
        expect(formatPercent(value)).toBe(expected);
    });
});
