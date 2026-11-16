import { describe, expect, it } from 'vitest';
import { formatMoney, formatNumber, formatPercent, formatDate } from './index';

describe('owner-approved display formats (not financial calculations)', () => {
  it.each([
    [0, '0,00\u00a0₽'],
    [-1234567.89, '−1\u00a0234\u00a0567,89\u00a0₽'],
    [1.25, '1,25\u00a0₽'],
    [
      '9007199254740993.25',
      '9\u00a0007\u00a0199\u00a0254\u00a0740\u00a0993,25\u00a0₽',
    ],
    [-0, '0,00\u00a0₽'],
    [-0.001, '0,00\u00a0₽'],
    [null, '—\u00a0₽'],
  ])('money %s -> exact Unicode %s', (value, expected) =>
    expect(formatMoney(value)).toBe(expected),
  );
  it('rounds decimal strings through Intl, without binary number coercion', () => {
    expect(formatMoney('1.005')).toBe('1,01\u00a0₽');
    expect(formatMoney('-1.005')).toBe('−1,01\u00a0₽');
  });
  it('makes whole-unit display and currency context explicit', () => {
    expect(formatMoney(1234.56, { fractionDigits: 0 })).toBe(
      '1\u00a0235\u00a0₽',
    );
    expect(formatMoney(12, { withCurrency: false })).toBe('12,00');
    expect(formatMoney(12, { currency: 'USD' })).toBe('$12,00');
    expect(formatMoney(12, { currency: 'EUR' })).toBe('€12,00');
  });
  it.each([NaN, Infinity, -Infinity, '', 'abc', 'Infinity', '0x10'])(
    'rejects invalid money %s',
    (value) => expect(() => formatMoney(value)).toThrow(RangeError),
  );
  it('number has groups/comma/U2212 and accepts exact bigint', () => {
    expect(formatNumber(-1234.5)).toBe('−1\u00a0234,5');
    expect(formatNumber(12345678901234567890n)).toBe(
      '12\u00a0345\u00a0678\u00a0901\u00a0234\u00a0567\u00a0890',
    );
    expect(formatNumber(null)).toBe('—');
  });
  it('percent input is percentage points, with explicit signed display', () => {
    expect(formatPercent(12.4, { sign: 'always' })).toBe('+12,4%');
    expect(formatPercent(-4.1)).toBe('−4,1%');
    expect(formatPercent(-0.001)).toBe('0,0%');
    expect(formatPercent(null)).toBe('—');
  });
  it('date-only stays a calendar date and instants have explicit timezone', () => {
    expect(formatDate('2026-01-01')).toBe('01.01.2026');
    expect(formatDate('2026-01-01', { timeZone: 'America/Los_Angeles' })).toBe(
      '01.01.2026',
    );
    expect(
      formatDate('2026-01-01T23:30:00Z', { timeZone: 'Europe/Moscow' }),
    ).toBe('02.01.2026');
    expect(formatDate(null)).toBe('—');
  });
  it.each([
    '2026-02-30',
    '2026-02-30T12:00:00Z',
    '2026-13-01',
    'nonsense',
    '2026-01-01T12:00:00',
  ])('rejects ambiguous or invalid date %s', (value) =>
    expect(() => formatDate(value)).toThrow(RangeError),
  );
});
