import { beforeEach, expect, it, vi } from 'vitest';
import { createSessionPresentation } from './presentation';
import type { SessionState } from './session';
let state: SessionState;
const listeners = new Set<() => void>();
const publish = (next: Partial<SessionState>) => {
  state = { ...state, ...next };
  listeners.forEach((listener) => listener());
};
vi.mock('./session', () => ({
  createSessionStore: () => ({
    getSnapshot: () => state,
    subscribe: (listener: () => void) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    login: async () =>
      publish({
        status: 'anonymous',
        data: null,
        error: 'Неверный email или пароль.',
        busy: false,
      }),
    logout: async () =>
      publish({
        status: 'anonymous',
        data: null,
        error: 'Logout 401',
        busy: false,
      }),
    refresh: async () =>
      publish({
        status: 'anonymous',
        data: null,
        error: 'Expired 401',
        busy: false,
      }),
    cancel: () => publish({ status: 'loading', data: null, error: null }),
  }),
}));
beforeEach(() => {
  listeners.clear();
  state = { status: 'loading', data: null, error: null, busy: false };
});
it('initial anonymous bootstrap is not an expired session', async () => {
  const store = createSessionPresentation();
  await store.refresh();
  expect(store.getSnapshot().expired).toBe(false);
});
it('records prior-auth 401 before notifying React subscribers', async () => {
  const store = createSessionPresentation();
  publish({
    status: 'authenticated',
    data: {
      user: { id: 'u', email: 'name@company.ru' },
      access: { admin: true },
    },
  });
  const observed: boolean[] = [];
  store.subscribe(() => observed.push(store.getSnapshot().expired));
  await store.refresh();
  expect(observed.at(-1)).toBe(true);
  expect(store.getSnapshot().expiredEmail).toBe('name@company.ru');
});
it('an explicit logout 401 is not expiry, nor is a later login error', async () => {
  const store = createSessionPresentation();
  publish({
    status: 'authenticated',
    data: {
      user: { id: 'u', email: 'name@company.ru' },
      access: { admin: true },
    },
  });
  await store.logout();
  expect(store.getSnapshot().expired).toBe(false);
  await store.login({ email: 'name@company.ru', password: 'bad' });
  expect(store.getSnapshot().expired).toBe(false);
});
it('preserves expiry across intermediate loading without leaking it after cancel', async () => {
  const store = createSessionPresentation();
  publish({
    status: 'authenticated',
    data: {
      user: { id: 'u', email: 'name@company.ru' },
      access: { admin: true },
    },
  });
  publish({ status: 'loading', data: null, error: null, busy: true });
  publish({
    status: 'anonymous',
    data: null,
    error: 'Expired 401',
    busy: false,
  });
  expect(store.getSnapshot().expired).toBe(true);
  store.cancel();
  expect(store.getSnapshot().expired).toBe(false);
});
