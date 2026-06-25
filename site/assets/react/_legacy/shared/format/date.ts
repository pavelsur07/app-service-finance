/**
 * Форматирование дат
 */

/**
 * Форматировать ISO дату в локализованную строку
 *
 * @example
 * formatDate("2026-02-23", "ru-RU", "Europe/Moscow")
 * // "23.02.2026"
 *
 * formatDateTime("2026-02-23T10:30:00Z", "ru-RU", "Europe/Moscow")
 * // "23.02.2026, 13:30"
 */
export function formatDate(
    iso: string,
    locale = "ru-RU",
    timeZone?: string
): string {
    const d = new Date(iso);

    return new Intl.DateTimeFormat(locale, {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        timeZone,
    }).format(d);
}

/**
 * Форматировать ISO дату со временем
 */
export function formatDateTime(
    iso: string,
    locale = "ru-RU",
    timeZone?: string
): string {
    const d = new Date(iso);

    return new Intl.DateTimeFormat(locale, {
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        timeZone,
    }).format(d);
}

/**
 * Форматировать только время
 */
export function formatTime(
    iso: string,
    locale = "ru-RU",
    timeZone?: string
): string {
    const d = new Date(iso);

    return new Intl.DateTimeFormat(locale, {
        hour: "2-digit",
        minute: "2-digit",
        timeZone,
    }).format(d);
}
