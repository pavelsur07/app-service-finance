// Заглушка assets/api/client.ts для браузерных тестов (браузер TS не исполняет): тот же контракт
// apiFetch — JSON/FormData тело, X-CSRF-Token, 204 → undefined, сообщение ошибки из легаси-тел.
export class ApiError extends Error {
  constructor(status, code, message) { super(message); this.status = status; this.code = code; }
}
export async function apiFetch(url, options = {}) {
  const multipart = options.body instanceof FormData;
  const headers = { Accept: 'application/json' };
  if (options.body !== undefined && !multipart) headers['Content-Type'] = 'application/json';
  if (options.csrfToken) headers['X-CSRF-Token'] = options.csrfToken;
  const body = options.body === undefined ? undefined : (multipart ? options.body : JSON.stringify(options.body));
  const r = await fetch(url, { method: options.method ?? 'GET', headers, body });
  let data;
  try { data = await r.json(); } catch { data = undefined; }
  if (!r.ok) {
    const legacy = data && ((typeof data.error === 'string' && data.error) || (typeof data.message === 'string' && data.message));
    throw new ApiError(r.status, data?.error?.code ?? 'http_error', data?.error?.message ?? legacy ?? `Ошибка сервера (${r.status}).`);
  }
  if (r.status === 204) return undefined;
  return data;
}
