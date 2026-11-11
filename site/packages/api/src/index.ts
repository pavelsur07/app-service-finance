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
  method?: 'GET' | 'POST' | 'DELETE';
  body?: unknown;
  csrfToken?: string;
  /** Запрос при закрытии страницы: браузер доотправит его после ухода. */
  keepalive?: boolean;
  /** Отмена: прерванный запрос бросает `AbortError` как есть, не `ApiError`. */
  signal?: AbortSignal;
}

/**
 * JSON-запрос к эндпоинтам вне OpenAPI-схемы (session cookie, `X-CSRF-Token`); `body` —
 * объект (JSON) или FormData (multipart, загрузка файла).
 * Ответ не 2xx → `ApiError` с кодом из тела; сбой сети → `ApiError(0, 'network_error')`;
 * 401 или редирект на вход — вызов адаптера приложения и ошибка авторизации; не-JSON
 * ответ 2xx — `ApiError('invalid_response')`, а не успех; 204 без тела — `undefined`.
 * Сообщение ошибки легаси-эндпоинтов (`{error: "текст"}`, `{type, message}`) тоже попадает в `ApiError`.
 */
export interface ApiAdapter {
  onUnauthenticated?: () => void;
}

export function createApiFetch(adapter: ApiAdapter = {}) {
  return async function apiFetch<T>(
    url: string,
    options: ApiFetchOptions = {},
  ): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    // FormData (загрузка файла) уходит как multipart: границу в Content-Type ставит браузер.
    const multipart = options.body instanceof FormData;
    if (options.body !== undefined && !multipart) {
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
        ...(options.body !== undefined
          ? {
              body: multipart
                ? (options.body as FormData)
                : (JSON.stringify(options.body) ?? null),
            }
          : {}),
        ...(options.keepalive !== undefined
          ? { keepalive: options.keepalive }
          : {}),
        ...(options.signal !== undefined ? { signal: options.signal } : {}),
      });
    } catch (error) {
      if (options.signal?.aborted) {
        throw error;
      }
      throw new ApiError(0, 'network_error', 'Нет связи с сервером.');
    }

    // Истёкшая сессия: form_login отвечает редиректом на /login, fetch идёт по нему и получает HTML 200.
    const toLogin =
      response.redirected &&
      new URL(response.url).pathname.startsWith('/login');
    if (response.status === 401 || toLogin) {
      adapter.onUnauthenticated?.();
      throw new ApiError(
        401,
        'unauthenticated',
        'Сессия истекла — войдите снова.',
      );
    }
    let data: unknown;
    try {
      data = await response.json();
    } catch {
      data = undefined;
    }
    if (!response.ok) {
      const error = isErrorBody(data) ? data.error : null;
      const message =
        error?.message ??
        legacyErrorMessage(data) ??
        `Ошибка сервера (${response.status}).`;
      throw new ApiError(response.status, error?.code ?? 'http_error', message);
    }
    if (response.status === 204) {
      return undefined as T;
    }
    if (data === undefined) {
      throw new ApiError(
        response.status,
        'invalid_response',
        'Сервер вернул неожиданный ответ.',
      );
    }

    return data as T;
  };
}

export const apiFetch = createApiFetch();

function isErrorBody(
  data: unknown,
): data is { error: { code: string; message: string } } {
  if (typeof data !== 'object' || data === null || !('error' in data)) {
    return false;
  }
  const error = (data as { error: unknown }).error;

  return (
    typeof error === 'object' &&
    error !== null &&
    typeof (error as { code?: unknown }).code === 'string' &&
    typeof (error as { message?: unknown }).message === 'string'
  );
}

/** Ошибки легаси-эндпоинтов до RFC 7807: `{error: "текст"}` или `{type, message}`. */
function legacyErrorMessage(data: unknown): string | null {
  if (typeof data !== 'object' || data === null) {
    return null;
  }
  const { error, message } = data as { error?: unknown; message?: unknown };
  if (typeof error === 'string' && error !== '') {
    return error;
  }

  return typeof message === 'string' && message !== '' ? message : null;
}
