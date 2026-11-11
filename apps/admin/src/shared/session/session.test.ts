import { afterAll, afterEach, beforeAll, expect, test } from 'vitest';
import { http, HttpResponse, delay } from 'msw';
import { setupServer } from 'msw/node';
import { createSessionStore, safeReturnPath } from './session';

const base = 'http://localhost/api/admin/v1';
const identity = {
  user: { id: 'one', email: 'one@example.test' },
  active_company: null,
  permissions: {
    finance: 'none',
    marketplace: 'none',
    deals: 'none',
    catalog: 'none',
    admin: 'none',
  },
  access: { admin: true },
};
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

test('initial 401 becomes anonymous; login 401 stays local with credential message', async () => {
  server.use(
    http.get(`${base}/session`, () => HttpResponse.json({}, { status: 401 })),
    http.get(`${base}/auth/csrf`, () =>
      HttpResponse.json({ tokens: { login: 'login' } }),
    ),
    http.post(`${base}/auth/login`, () =>
      HttpResponse.json({}, { status: 401 }),
    ),
  );
  const store = createSessionStore(base);
  await store.refresh();
  expect(store.getSnapshot().status).toBe('anonymous');
  await store.login({ email: 'one@example.test', password: 'wrong' });
  expect(store.getSnapshot()).toMatchObject({
    status: 'anonymous',
    data: null,
    error: 'Неверный email или пароль.',
  });
});

test('login sends credentials and CSRF, then restores identity; logout drops it', async () => {
  server.use(
    http.get(`${base}/auth/csrf`, () =>
      HttpResponse.json({ tokens: { login: 'login', logout: 'logout' } }),
    ),
    http.post(`${base}/auth/login`, async ({ request }) => {
      expect(request.credentials).toBe('include');
      expect(request.headers.get('X-CSRF-Token')).toBe('login');
      expect(await request.json()).toEqual({
        email: 'one@example.test',
        password: 'correct',
      });
      return new HttpResponse(null, { status: 204 });
    }),
    http.get(`${base}/session`, () => HttpResponse.json(identity)),
    http.post(`${base}/auth/logout`, ({ request }) => {
      expect(request.headers.get('X-CSRF-Token')).toBe('logout');
      expect(request.headers.has('Content-Type')).toBe(false);
      return new HttpResponse(null, { status: 204 });
    }),
  );
  const store = createSessionStore(base);
  await store.login({ email: 'one@example.test', password: 'correct' });
  expect(store.getSnapshot().data?.user.email).toBe('one@example.test');
  await store.logout();
  expect(store.getSnapshot()).toMatchObject({
    status: 'anonymous',
    data: null,
  });
});

test('old session response cannot resurrect identity after logout', async () => {
  server.use(
    http.get(`${base}/session`, async () => {
      await delay(100);
      return HttpResponse.json(identity);
    }),
    http.get(`${base}/auth/csrf`, () =>
      HttpResponse.json({ tokens: { logout: 'logout' } }),
    ),
    http.post(
      `${base}/auth/logout`,
      () => new HttpResponse(null, { status: 204 }),
    ),
  );
  const store = createSessionStore(base);
  const pending = store.refresh();
  await store.logout();
  await pending;
  expect(store.getSnapshot()).toMatchObject({
    status: 'anonymous',
    data: null,
  });
});

test.each([403, 429, 500, 0])(
  'login failure %s never pretends to authenticate',
  async (status) => {
    server.use(
      http.get(`${base}/auth/csrf`, () =>
        HttpResponse.json({ tokens: { login: 'login' } }),
      ),
      http.post(`${base}/auth/login`, () =>
        status
          ? HttpResponse.json(
              { error: { code: 'failure', message: 'Отказ' } },
              { status },
            )
          : HttpResponse.error(),
      ),
      http.get(`${base}/session`, () => HttpResponse.json({}, { status: 401 })),
    );
    const store = createSessionStore(base);
    await store.login({ email: 'one@example.test', password: 'wrong' });
    expect(store.getSnapshot().data).toBeNull();
    expect(store.getSnapshot().busy).toBe(false);
    expect(store.getSnapshot().error).toBeTruthy();
  },
);

test('restoration error permits explicit retry and cancel discards late data', async () => {
  server.use(
    http.get(`${base}/session`, () => HttpResponse.json({}, { status: 500 })),
  );
  const store = createSessionStore(base);
  await store.refresh();
  expect(store.getSnapshot().status).toBe('error');
  server.use(http.get(`${base}/session`, () => HttpResponse.json(identity)));
  await store.refresh();
  expect(store.getSnapshot().status).toBe('authenticated');
  server.use(
    http.get(`${base}/session`, async () => {
      await delay(50);
      return HttpResponse.json(identity);
    }),
  );
  const pending = store.refresh();
  store.cancel();
  await pending;
  expect(store.getSnapshot().data).toBeNull();
});

test.each([
  'https://evil.test',
  '//evil.test',
  '/api/account/v1/session',
  '/login',
  '/missing',
  '/\\evil.test',
])('rejects unsafe return path %s', (value) => {
  expect(safeReturnPath(value)).toBe('/');
});
