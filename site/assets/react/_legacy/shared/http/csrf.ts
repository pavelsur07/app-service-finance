/**
 * CSRF helpers для работы с токенами
 */

/**
 * Прочитать CSRF токен из meta тега
 *
 * Если в вашем layout есть:
 * <meta name="csrf-token" content="...">
 *
 * То можно использовать этот helper для получения токена.
 *
 * @example
 * const csrf = readCsrfFromMeta();
 * await httpJson('/api/users', { method: 'POST', csrfToken: csrf });
 */
export function readCsrfFromMeta(metaName = "csrf-token"): string | null {
    const el = document.querySelector<HTMLMetaElement>(`meta[name="${metaName}"]`);
    return el?.content || null;
}

/**
 * Прочитать CSRF токен из data-атрибута элемента
 *
 * @example
 * <div id="react-app" data-csrf="..."></div>
 * const csrf = readCsrfFromElement('react-app');
 */
export function readCsrfFromElement(elementId: string): string | null {
    const el = document.getElementById(elementId);
    return el?.dataset?.csrf || null;
}
