import createClient from 'openapi-fetch';
import type { paths } from './schema';

/**
 * Типизированный клиент для внутреннего API VashFinDir.
 *
 * # Аутентификация
 * Через session cookie PHPSESSID, credentials: 'include' отправляется
 * автоматически для всех запросов.
 *
 * # CSRF
 * Защита обеспечивается на уровне cookie policy (SameSite=Lax на
 * PHPSESSID, см. config/packages/framework.yaml). Это стандартная
 * практика для cookie-based API в одном origin с UI.
 *
 * Если в будущем будут выделяться кросс-доменные клиенты или
 * публичный API — потребуется явный CSRF-токен через заголовок
 * X-CSRF-Token и соответствующий ExceptionSubscriber на бэке.
 * Это отдельная задача уровня реформы API.
 *
 * # Ошибки
 * Текущие эндпоинты возвращают разные форматы ошибок (legacy).
 * Целевой формат — RFC 7807 (схема Problem), но миграция постепенная.
 * error из openapi-fetch содержит response.data — разбирать по
 * месту с оглядкой на реальный формат конкретного эндпоинта.
 */
export const api = createClient<paths>({
    baseUrl: '',
    credentials: 'include',
});

/**
 * Ошибка JSON-эндпоинта. `code` и `message` — из тела `{error: {code, message}}`
 * (формат ошибок нового кода, CLAUDE.md «Обработка ошибок»); сеть — status 0.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly code: string,
        message: string,
    ) {
        super(message);
        this.name = 'ApiError';
    }
}

export interface ApiFetchOptions {
    method?: 'GET' | 'POST';
    body?: unknown;
    csrfToken?: string;
    /** Запрос при закрытии страницы: браузер доотправит его после ухода. */
    keepalive?: boolean;
}

/**
 * JSON-запрос к эндпоинтам вне OpenAPI-схемы (session cookie, `X-CSRF-Token`).
 * Ответ не 2xx → `ApiError` с кодом из тела; сбой сети → `ApiError(0, 'network_error')`;
 * 401 (сессия истекла) — переход на страницу входа.
 */
export async function apiFetch<T>(url: string, options: ApiFetchOptions = {}): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (options.body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }
    if (options.csrfToken) {
        headers['X-CSRF-Token'] = options.csrfToken;
    }

    let response: Response;
    try {
        response = await fetch(url, {
            method: options.method ?? 'GET',
            credentials: 'include',
            headers,
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
            keepalive: options.keepalive,
        });
    } catch {
        throw new ApiError(0, 'network_error', 'Нет связи с сервером.');
    }

    if (response.status === 401) {
        // Сессия истекла — на страницу входа.
        window.location.assign('/login');
    }
    const data: unknown = await response.json().catch(() => null);
    if (!response.ok) {
        const error = isErrorBody(data) ? data.error : null;
        throw new ApiError(response.status, error?.code ?? 'http_error', error?.message ?? `Ошибка сервера (${response.status}).`);
    }

    return data as T;
}

function isErrorBody(data: unknown): data is { error: { code: string; message: string } } {
    if (typeof data !== 'object' || data === null || !('error' in data)) {
        return false;
    }
    const error = (data as { error: unknown }).error;

    return typeof error === 'object' && error !== null
        && typeof (error as { code?: unknown }).code === 'string'
        && typeof (error as { message?: unknown }).message === 'string';
}
