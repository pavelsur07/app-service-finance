/**
 * HTTP клиент для API запросов
 * Единая точка для всех fetch запросов с обработкой ошибок
 */

export type ApiErrorKind =
    | "unauthorized"
    | "forbidden"
    | "conflict"
    | "validation"
    | "network"
    | "server"
    | "unknown";

/**
 * Класс ошибки API с типизацией
 */
export class ApiError extends Error {
    constructor(
        message: string,
        public readonly kind: ApiErrorKind,
        public readonly status?: number,
        public readonly details?: unknown
    ) {
        super(message);
        this.name = "ApiError";
    }
}

type RequestOptions = {
    method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
    query?: Record<string, string | number | boolean | null | undefined>;
    body?: unknown;
    signal?: AbortSignal;
    csrfToken?: string | null;
};

/**
 * Построить URL с query параметрами
 */
function buildUrl(url: string, query?: RequestOptions["query"]): string {
    if (!query) return url;

    const u = new URL(url, window.location.origin);

    Object.entries(query).forEach(([key, value]) => {
        if (value === null || value === undefined) return;
        u.searchParams.set(key, String(value));
    });

    return u.toString();
}

/**
 * Безопасный парсинг JSON ответа
 */
async function parseJsonSafe(res: Response): Promise<any> {
    const text = await res.text();
    if (!text) return null;

    try {
        return JSON.parse(text);
    } catch {
        return { raw: text };
    }
}

/**
 * Универсальный HTTP клиент для JSON API
 *
 * @example
 * const data = await httpJson<UserDto>('/api/users', {
 *   method: 'GET',
 *   query: { page: 1 },
 *   signal: abortController.signal
 * });
 */
export async function httpJson<T>(
    url: string,
    opts: RequestOptions = {}
): Promise<T> {
    const method = opts.method ?? "GET";
    const fullUrl = buildUrl(url, opts.query);

    const headers: Record<string, string> = {
        Accept: "application/json",
    };

    let body: string | undefined;
    if (opts.body !== undefined) {
        headers["Content-Type"] = "application/json";
        body = JSON.stringify(opts.body);
    }

    // CSRF для мутаций (POST/PUT/PATCH/DELETE)
    const isMutation = method !== "GET";
    if (isMutation && opts.csrfToken) {
        headers["X-CSRF-Token"] = opts.csrfToken;
    }

    let res: Response;
    try {
        res = await fetch(fullUrl, {
            method,
            headers,
            body,
            credentials: "same-origin", // Включаем куки для session-based auth
            signal: opts.signal,
        });
    } catch (e: any) {
        // AbortError - не считаем ошибкой, просто пробрасываем
        if (e?.name === "AbortError") {
            throw e;
        }
        // Сетевая ошибка (нет интернета, CORS, etc)
        throw new ApiError(
            "Сеть недоступна. Проверьте подключение и повторите.",
            "network"
        );
    }

    // Успешный ответ
    if (res.ok) {
        // 204 No Content
        if (res.status === 204) {
            return null as unknown as T;
        }
        return (await parseJsonSafe(res)) as T;
    }

    // Ошибка от сервера
    const payload = await parseJsonSafe(res);

    // Стандартизированные сообщения по статус-кодам
    switch (res.status) {
        case 401:
            throw new ApiError(
                "Сессия истекла. Обновите страницу.",
                "unauthorized",
                401,
                payload
            );

        case 403:
            throw new ApiError(
                "Недостаточно прав для этого действия.",
                "forbidden",
                403,
                payload
            );

        case 409:
            throw new ApiError(
                "Конфликт изменений или период закрыт.",
                "conflict",
                409,
                payload
            );

        case 422:
            throw new ApiError(
                "Проверьте корректность данных.",
                "validation",
                422,
                payload
            );

        default:
            if (res.status >= 500) {
                throw new ApiError(
                    "Сервис временно недоступен. Повторите позже.",
                    "server",
                    res.status,
                    payload
                );
            }

            throw new ApiError(
                "Ошибка запроса.",
                "unknown",
                res.status,
                payload
            );
    }
}
