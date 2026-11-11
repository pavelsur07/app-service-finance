import {
  afterAll,
  afterEach,
  beforeAll,
  describe,
  expect,
  it,
  vi,
} from 'vitest';
import { delay, http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import type { ApiFetchOptions } from '../src';

// Shared executable contract runs against each application's adapter.
export function transportContract(
  apiFetch: <T>(url: string, options?: ApiFetchOptions) => Promise<T>,
): void {
  const endpoint = 'http://finance.test/transport';
  const unexpected: string[] = [];
  const server = setupServer();
  // A sentinel fallback proves MSW cannot bypass into a real network call.
  const networkFallback = vi.fn(async () => Response.json({ escaped: true }));
  beforeAll(() => {
    vi.stubGlobal('fetch', networkFallback);
    server.events.on('request:unhandled', ({ request }) => {
      unexpected.push(`${request.method} ${request.url}`);
    });
    server.listen({ onUnhandledRequest: 'error' });
  });

  afterEach(() => {
    server.resetHandlers();
    expect(networkFallback).not.toHaveBeenCalled();
    const requests = unexpected.splice(0);
    expect(
      requests,
      'Unhandled MSW requests must never reach the network',
    ).toEqual([]);
  });
  afterAll(() => {
    server.close();
    vi.unstubAllGlobals();
  });

  describe('shared JSON transport', () => {
    it('blocks an unhandled request before the network fallback', async () => {
      const logged = vi
        .spyOn(console, 'error')
        .mockImplementation(() => undefined);
      try {
        await expect(apiFetch(endpoint)).rejects.toMatchObject({
          status: 0,
          code: 'network_error',
        });
        expect(unexpected.splice(0)).toEqual([`GET ${endpoint}`]);
      } finally {
        logged.mockRestore();
      }
    });

    it('sends credentials, JSON and CSRF', async () => {
      let received:
        | {
            credentials: string;
            csrf: string | null;
            accept: string | null;
            body: unknown;
          }
        | undefined;
      server.use(
        http.post(endpoint, async ({ request }) => {
          received = {
            credentials: request.credentials,
            csrf: request.headers.get('X-CSRF-Token'),
            accept: request.headers.get('Accept'),
            body: await request.json(),
          };
          return HttpResponse.json({ ok: true });
        }),
      );
      await expect(
        apiFetch(endpoint, {
          method: 'POST',
          body: { value: 1 },
          csrfToken: 'token',
        }),
      ).resolves.toEqual({ ok: true });
      expect(received).toEqual({
        credentials: 'include',
        csrf: 'token',
        accept: 'application/json',
        body: { value: 1 },
      });
    });
    it('accepts 204 without a body', async () => {
      server.use(
        http.get(endpoint, () => new HttpResponse(null, { status: 204 })),
      );
      await expect(apiFetch(endpoint)).resolves.toBeUndefined();
    });
    it.each([401, 403, 422, 500])(
      'normalizes HTTP %s without navigating',
      async (status) => {
        server.use(
          http.get(endpoint, () =>
            HttpResponse.json(
              {
                error: {
                  code: 'denied',
                  message: 'Нет доступа',
                },
              },
              { status },
            ),
          ),
        );
        await expect(apiFetch(endpoint)).rejects.toMatchObject(
          status === 401
            ? {
                status: 401,
                code: 'unauthenticated',
                message: 'Сессия истекла — войдите снова.',
              }
            : { status, code: 'denied', message: 'Нет доступа' },
        );
      },
    );
    it.each(['<html>unexpected</html>', '{invalid'])(
      'rejects non-JSON success: %s',
      async (body) => {
        server.use(http.get(endpoint, () => new HttpResponse(body)));
        await expect(apiFetch(endpoint)).rejects.toMatchObject({
          status: 200,
          code: 'invalid_response',
        });
      },
    );
    it('normalizes HTML server errors', async () => {
      server.use(
        http.get(endpoint, () =>
          HttpResponse.html('<html>error</html>', { status: 500 }),
        ),
      );
      await expect(apiFetch(endpoint)).rejects.toMatchObject({
        status: 500,
        code: 'http_error',
        message: 'Ошибка сервера (500).',
      });
    });
    it('normalizes network errors', async () => {
      server.use(http.get(endpoint, () => HttpResponse.error()));
      await expect(apiFetch(endpoint)).rejects.toMatchObject({
        status: 0,
        code: 'network_error',
      });
    });
    it('preserves AbortError', async () => {
      server.use(
        http.get(endpoint, async () => {
          await delay(100);
          return HttpResponse.json({});
        }),
      );
      const controller = new AbortController();
      const result = apiFetch(endpoint, { signal: controller.signal });
      controller.abort();
      await expect(result).rejects.toMatchObject({ name: 'AbortError' });
    });
    it('lets the browser encode FormData', async () => {
      let contentType: string | null = null;
      let value: unknown;
      server.use(
        http.post(endpoint, async ({ request }) => {
          contentType = request.headers.get('Content-Type');
          value = (await request.formData()).get('name');
          return HttpResponse.json({});
        }),
      );
      const body = new FormData();
      body.set('name', 'upload');
      await apiFetch(endpoint, { method: 'POST', body });
      expect(contentType).toContain('multipart/form-data; boundary=');
      expect(value).toBe('upload');
    });
  });
}
