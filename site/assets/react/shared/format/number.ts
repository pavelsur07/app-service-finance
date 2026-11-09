/**
 * Форматирование денежной суммы в рубли
 */
export function formatMoney(value: string | number | null | undefined, decimals = 0): string {
    if (value === null || value === undefined) return '—';
    const num = typeof value === 'string' ? parseFloat(value) : value;
    if (Number.isNaN(num)) return '—';

    return num.toLocaleString('ru-RU', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }) + ' \u20BD';
}

/**
 * Форматирование количества. null/undefined = значение неизвестно, а не ноль:
 * показываем прочерк, как и для денежных колонок.
 */
export function formatQty(value: number | null | undefined, maxDecimals = 3): string {
    if (value === null || value === undefined || Number.isNaN(value)) return '\u2014';

    return value.toLocaleString('ru-RU', {
        minimumFractionDigits: 0,
        maximumFractionDigits: maxDecimals,
    });
}
