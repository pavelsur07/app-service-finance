import { apiFetch, ApiError } from './index';

export interface Credentials {
  email: string;
  password: string;
}
/** Cookie/CSRF transport only. Routing and session state belong to each app. */
export function createSessionApi<T>(base: string) {
  const getSession = (signal: AbortSignal) =>
    apiFetch<T>(`${base}/session`, { signal });
  const mutate = async <R>(
    path: string,
    purpose: string,
    signal: AbortSignal,
    body?: unknown,
  ) => {
    const { tokens } = await apiFetch<{ tokens: Record<string, string> }>(
      `${base}/auth/csrf`,
      { signal },
    );
    const csrfToken = tokens[purpose];
    if (!csrfToken)
      throw new ApiError(
        0,
        'missing_csrf',
        'Не удалось подготовить безопасный запрос. Повторите попытку.',
      );
    return apiFetch<R>(`${base}${path}`, {
      method: 'POST',
      csrfToken,
      signal,
      ...(body === undefined ? {} : { body }),
    });
  };
  return {
    getSession,
    mutate,
    login: async (credentials: Credentials, signal: AbortSignal) => {
      await mutate<void>('/auth/login', 'login', signal, credentials);
      return getSession(signal);
    },
    logout: (signal: AbortSignal) =>
      mutate<void>('/auth/logout', 'logout', signal),
  };
}
