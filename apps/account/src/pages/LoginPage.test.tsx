// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import { MemoryRouter, Route, Routes } from 'react-router';
import { LoginPage } from './LoginPage';
import { cleanup, fill, input, render, submit } from '../../tests/dom';
import type { SessionState } from '../shared/session/session';

let state: SessionState & { expiredEmail?: string | null };
const login = vi.fn(async () => {});
vi.mock('../app/SessionProvider', () => ({
  useSession: () => ({
    state,
    expired: false,
    store: { login, getSnapshot: () => state },
  }),
}));
async function mount(path = '/login') {
  await render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/" element={<p>SAFE HOME</p>} />
        <Route path="/about" element={<p>SAFE ABOUT</p>} />
      </Routes>
    </MemoryRouter>,
  );
}
beforeEach(() => {
  state = { status: 'anonymous', data: null, error: null, busy: false };
  login.mockClear();
  document.cookie = 'vf_cookie_consent=; Max-Age=0; Path=/';
});
afterEach(cleanup);
describe('LoginPage', () => {
  it('empty submit exposes both errors and focuses email without API call', async () => {
    await mount();
    await submit();
    expect(document.body.textContent).toContain('Укажите email');
    expect(document.body.textContent).toContain('Введите пароль');
    expect(document.activeElement).toBe(input('email'));
    expect(input('email').getAttribute('aria-invalid')).toBe('true');
    expect(input('password').getAttribute('aria-describedby')).toContain(
      'error',
    );
    expect(login).not.toHaveBeenCalled();
  });
  it('validates email after blur and after submit', async () => {
    await mount();
    await fill('email', 'invalid');
    await act(async () => {
      input('email').focus();
      input('email').blur();
    });
    expect(document.body.textContent).toContain(
      'Проверьте адрес: нужен формат name@company.ru',
    );
    await submit();
    expect(login).not.toHaveBeenCalled();
  });
  it('401 clears password, preserves email and focuses password with generic alert', async () => {
    await mount();
    await fill('email', 'name@company.ru');
    await fill('password', 'bad');
    login.mockImplementationOnce(async () => {
      state = { ...state, error: 'Неверный email или пароль.' };
    });
    await submit();
    expect(document.querySelector('[role="alert"]')?.textContent).toContain(
      'Проверьте раскладку и Caps Lock или восстановите пароль.',
    );
    expect(input('password').value).toBe('');
    expect(input('email').value).toBe('name@company.ru');
    expect(document.activeElement).toBe(input('password'));
    await fill('password', 'bad-again');
    await act(async () =>
      document
        .querySelector<HTMLButtonElement>('button[type="submit"]')
        ?.focus(),
    );
    await submit();
    expect(document.activeElement).toBe(input('password'));
  });
  it('loading disables controls and marks form busy', async () => {
    state.busy = true;
    await mount();
    expect(input('email').disabled).toBe(true);
    expect(input('password').disabled).toBe(true);
    expect(document.querySelector('form')?.getAttribute('aria-busy')).toBe(
      'true',
    );
    expect(document.body.textContent).toContain('Входим…');
  });
  it('password eye toggles type and aria-pressed', async () => {
    await mount();
    const eye = document.querySelector<HTMLButtonElement>(
      '[aria-label="Показать пароль"]',
    );
    expect(eye).not.toBeNull();
    await act(async () => eye?.click());
    expect(input('password').type).toBe('text');
    expect(eye?.getAttribute('aria-pressed')).toBe('true');
    expect(eye?.getAttribute('aria-label')).toBe('Скрыть пароль');
  });
  it('expired info only appears for explicit expiry reason', async () => {
    state.expiredEmail = 'known@company.ru';
    await mount('/login?reason=expired&returnTo=%2Fabout');
    expect(input('email').value).toBe('known@company.ru');
    expect(document.activeElement).toBe(input('password'));
    expect(document.body.textContent).toContain(
      'Сессия истекла. Войдите снова — вернём вас на ту же страницу.',
    );
  });
  it('ordinary guest has no expired info', async () => {
    await mount();
    expect(document.body.textContent).not.toContain('Сессия истекла.');
  });
  it.each(['/about', 'https://evil.test', '//evil.test'])(
    'redirects through safeReturnPath: %s',
    async (returnTo) => {
      state = {
        ...state,
        status: 'authenticated',
        data: {
          user: { id: 'u', email: 'name@company.ru' },
          active_company: null,
          permissions: {
            finance: 'none',
            marketplace: 'none',
            deals: 'none',
            catalog: 'none',
            admin: 'none',
          },
        },
      };
      await mount(`/login?returnTo=${encodeURIComponent(returnTo)}`);
      expect(document.body.textContent).toContain(
        returnTo === '/about' ? 'SAFE ABOUT' : 'SAFE HOME',
      );
    },
  );
});

it.each([
  ['Только необходимые', 'necessary'],
  ['Принять все', 'all'],
])('cookie choice %s persists and emits an event', async (label, level) => {
  await mount();
  const listener = vi.fn();
  document.addEventListener('vf:cookie-consent', listener, { once: true });
  const button = [...document.querySelectorAll('button')].find(
    (node) => node.textContent === label,
  );
  expect(
    document.querySelector('[role="region"][aria-label="Файлы cookie"]'),
  ).not.toBeNull();
  await act(async () => button?.click());
  expect(document.cookie).toContain(`vf_cookie_consent=${level}`);
  expect(listener).toHaveBeenCalledOnce();
  expect((listener.mock.calls[0]?.[0] as CustomEvent).detail).toEqual({
    level,
  });
  expect(document.querySelector('[aria-label="Файлы cookie"]')).toBeNull();
  document.removeEventListener('vf:cookie-consent', listener);
});
