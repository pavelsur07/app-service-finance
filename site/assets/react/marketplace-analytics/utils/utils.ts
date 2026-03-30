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
 * Форматирование даты YYYY-MM-DD → DD.MM.YYYY
 */
export function formatDate(dateStr: string | null | undefined): string {
    if (!dateStr) return '—';

    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;

    return `${parts[2]}.${parts[1]}.${parts[0]}`;
}

/**
 * Дефолтный диапазон дат: последние 30 дней (вчерашний день — 30 дней назад)
 */
export function getDefaultDateRange(): { dateFrom: string; dateTo: string } {
    const today = new Date();
    const dateTo = new Date(today);
    dateTo.setDate(today.getDate() - 1);

    const dateFrom = new Date(dateTo);
    dateFrom.setDate(dateTo.getDate() - 29);

    return {
        dateFrom: toISODate(dateFrom),
        dateTo: toISODate(dateTo),
    };
}

function toISODate(d: Date): string {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}
