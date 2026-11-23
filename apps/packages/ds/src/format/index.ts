export type NumericValue = number | bigint | string | null | undefined;
export interface NumberFormatOptions {
  fractionDigits?: number;
  sign?: 'auto' | 'always';
}
export interface MoneyFormatOptions extends NumberFormatOptions {
  currency?: 'RUB' | 'USD' | 'EUR';
  withCurrency?: boolean;
}
export interface DateFormatOptions {
  timeZone?: string;
  includeTime?: boolean;
}
function numeric(
  value: Exclude<NumericValue, null | undefined>,
  digits: number,
  fixed: boolean,
  sign: 'auto' | 'always',
): string {
  if (!Number.isInteger(digits) || digits < 0 || digits > 20)
    throw new RangeError('fractionDigits must be 0–20');
  if (
    (typeof value === 'number' && !Number.isFinite(value)) ||
    (typeof value === 'string' &&
      !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(value))
  )
    throw new RangeError('Expected a finite decimal value');
  const formatter = new Intl.NumberFormat('ru-RU', {
    minimumFractionDigits: fixed ? digits : 0,
    maximumFractionDigits: digits,
    signDisplay: sign === 'always' ? 'always' : 'auto',
  });
  // Intl accepts exact decimal strings; do not coerce server decimals to binary Number.
  const parts: Intl.NumberFormatPart[] = Reflect.apply(
    formatter.formatToParts,
    formatter,
    [value],
  );
  if (parts.some((part) => part.type === 'infinity' || part.type === 'nan'))
    throw new RangeError('Expected a finite decimal value');
  const zero = parts
    .filter((part) => part.type === 'integer' || part.type === 'fraction')
    .every((part) => !/[1-9]/.test(part.value));
  return parts
    .filter(
      (part) =>
        !(zero && (part.type === 'minusSign' || part.type === 'plusSign')),
    )
    .map((part) =>
      part.type === 'group'
        ? '\u00a0'
        : part.type === 'minusSign'
          ? '−'
          : part.value,
    )
    .join('');
}
/** Display only. Defaults to two decimal places; whole-unit rounding must be explicit. */
export function formatMoney(
  value: NumericValue,
  {
    fractionDigits = 2,
    sign = 'auto',
    currency = 'RUB',
    withCurrency = true,
  }: MoneyFormatOptions = {},
): string {
  const text = value == null ? '—' : numeric(value, fractionDigits, true, sign);
  if (!withCurrency) return text;
  return currency === 'RUB'
    ? `${text}\u00a0₽`
    : `${currency === 'USD' ? '$' : '€'}${text}`;
}
export function formatNumber(
  value: NumericValue,
  { fractionDigits = 2, sign = 'auto' }: NumberFormatOptions = {},
): string {
  return value == null ? '—' : numeric(value, fractionDigits, false, sign);
}
/** Input is percentage points (12.4 -> 12,4%), never a silently rescaled ratio. */
export function formatPercent(
  value: NumericValue,
  { fractionDigits = 1, sign = 'auto' }: NumberFormatOptions = {},
): string {
  return value == null ? '—' : `${numeric(value, fractionDigits, true, sign)}%`;
}
/** Calendar dates do not shift timezone; instants default to UTC, not the host timezone. */
export function formatDate(
  value: Date | string | number | null | undefined,
  { timeZone = 'UTC', includeTime = false }: DateFormatOptions = {},
): string {
  if (value == null) return '—';
  const dateOnly =
    typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value);
  if (
    typeof value === 'string' &&
    !dateOnly &&
    !/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/i.test(value)
  )
    throw new RangeError('Expected an ISO date or timezone-qualified instant');
  if (typeof value === 'string') {
    const calendar = value.slice(0, 10);
    const midnight = new Date(`${calendar}T00:00:00Z`);
    if (
      !Number.isFinite(midnight.getTime()) ||
      midnight.toISOString().slice(0, 10) !== calendar
    )
      throw new RangeError('Invalid calendar date');
  }
  const date =
    value instanceof Date
      ? value
      : new Date(dateOnly ? `${value}T00:00:00Z` : value);
  if (
    !Number.isFinite(date.getTime()) ||
    (dateOnly && date.toISOString().slice(0, 10) !== value)
  )
    throw new RangeError('Invalid date');
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: dateOnly ? 'UTC' : timeZone,
    ...(includeTime ? ({ hour: '2-digit', minute: '2-digit' } as const) : {}),
  }).format(date);
}
