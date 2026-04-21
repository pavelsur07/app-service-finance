/**
 * Форматирование денежной суммы из decimal-строки в "1 234,56".
 * Пустая / некорректная строка → "0,00".
 */
export function formatRub(value: string): string {
    const num = Number(value);
    if (!Number.isFinite(num)) {
        return '0,00';
    }
    return new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(num);
}

/**
 * Форматирование ДРР из decimal-строки в "12,34%".
 * null / "0" → "—".
 */
export function formatDrr(value: string | null): string {
    if (value === null || value === '0') {
        return '—';
    }
    const num = Number(value);
    if (!Number.isFinite(num) || num === 0) {
        return '—';
    }
    return (
        new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(num) + '%'
    );
}
