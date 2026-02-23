/**
 * Форматирование денежных сумм
 */

export type Money = {
    amount: number; // В минимальных единицах (копейки)
    currency: string; // ISO код валюты
};

/**
 * Форматировать деньги в локализованную строку
 *
 * @example
 * formatMoney({ amount: 425000000, currency: "RUB" }, "ru-RU")
 * // "4 250 000,00 ₽"
 *
 * formatMoney({ amount: 100050, currency: "USD" }, "en-US")
 * // "$1,000.50"
 */
export function formatMoney(m: Money, locale = "ru-RU"): string {
    // Конвертируем из копеек в основную валюту
    const amount = m.amount / 100;

    return new Intl.NumberFormat(locale, {
        style: "currency",
        currency: m.currency,
        currencyDisplay: "symbol",
        maximumFractionDigits: 2,
    }).format(amount);
}

/**
 * Форматировать число с разделителями тысяч
 *
 * @example
 * formatNumber(4250000, "ru-RU")
 * // "4 250 000"
 */
export function formatNumber(value: number, locale = "ru-RU"): string {
    return new Intl.NumberFormat(locale, {
        maximumFractionDigits: 0,
    }).format(value);
}
