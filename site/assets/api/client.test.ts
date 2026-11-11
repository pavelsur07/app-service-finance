import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiFetch, ApiError } from './client';

afterEach(() => vi.unstubAllGlobals());

function reply(response: Response) {
    const fetchMock = vi.fn().mockResolvedValue(response);
    vi.stubGlobal('fetch', fetchMock);
    return fetchMock;
}

describe('existing finance API contract', () => {
    it('sends JSON, credentials, CSRF, keepalive and signal', async () => {
        const fetchMock = reply(Response.json({ ok: true }));
        const signal = new AbortController().signal;
        await expect(apiFetch('/test', { method: 'POST', body: { value: 1 }, csrfToken: 'token', keepalive: true, signal })).resolves.toEqual({ ok: true });
        expect(fetchMock).toHaveBeenCalledWith('/test', {
            method: 'POST', credentials: 'include',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': 'token' },
            body: '{"value":1}', keepalive: true, signal,
        });
    });
    it('lets the browser set the multipart boundary', async () => {
        const fetchMock = reply(new Response(null, { status: 204 }));
        const body = new FormData();
        body.set('file', new Blob(['example']), 'example.txt');
        await expect(apiFetch('/test', { method: 'POST', body })).resolves.toBeUndefined();
        expect(fetchMock.mock.calls[0]?.[1]).toMatchObject({ body, headers: { Accept: 'application/json' } });
        expect(fetchMock.mock.calls[0]?.[1].headers).not.toHaveProperty('Content-Type');
    });
    it.each([401, 'redirect'])('redirects an expired session (%s) to login', async (kind) => {
        const assign = vi.fn();
        vi.stubGlobal('window', { location: { assign } });
        const response = new Response('<html>login</html>', { status: kind === 401 ? 401 : 200 });
        if (kind === 'redirect') {
            Object.defineProperties(response, { redirected: { value: true }, url: { value: 'https://finance.test/login' } });
        }
        reply(response);
        await expect(apiFetch('/test')).rejects.toMatchObject({ status: 401, code: 'unauthenticated' });
        expect(assign).toHaveBeenCalledWith('/login');
    });
    it.each([403, 422, 500])('normalizes structured HTTP %s errors', async (status) => {
        reply(Response.json({ error: { code: 'test_error', message: 'Сообщение' } }, { status }));
        await expect(apiFetch('/test')).rejects.toEqual(new ApiError(status, 'test_error', 'Сообщение'));
    });
    it.each([{ error: 'Legacy' }, { type: 'error', message: 'Legacy' }])('retains legacy error messages: %j', async (body) => {
        reply(Response.json(body, { status: 422 }));
        await expect(apiFetch('/test')).rejects.toMatchObject({ message: 'Legacy', code: 'http_error' });
    });
    it.each(['<html>error</html>', '{invalid'])('rejects unexpected successful bodies (%s)', async (body) => {
        reply(new Response(body));
        await expect(apiFetch('/test')).rejects.toMatchObject({ status: 200, code: 'invalid_response' });
    });
    it('normalizes HTTP errors without JSON', async () => {
        reply(new Response('<html>error</html>', { status: 500 }));
        await expect(apiFetch('/test')).rejects.toMatchObject({ status: 500, message: 'Ошибка сервера (500).' });
    });
    it('normalizes network errors', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('failed')));
        await expect(apiFetch('/test')).rejects.toMatchObject({ status: 0, code: 'network_error' });
    });
    it('preserves cancellation', async () => {
        const controller = new AbortController();
        controller.abort();
        const error = new DOMException('aborted', 'AbortError');
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(error));
        await expect(apiFetch('/test', { signal: controller.signal })).rejects.toBe(error);
    });
});
