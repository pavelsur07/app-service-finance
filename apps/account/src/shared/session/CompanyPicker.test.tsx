// @vitest-environment jsdom
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { act } from 'react';
import { CompanyPicker } from './CompanyPicker';
import { cleanup, render } from '../../../tests/dom';
import type { Companies, SessionState } from './session';
let state: SessionState;
let companies: Companies;
const selectCompany = vi.fn(async () => {});
const refresh = vi.fn(async () => {});
const logout = vi.fn(async () => {});
const getCompanies = vi.fn(async () => companies);
const store = {
  getCompanies,
  selectCompany,
  refresh,
  logout,
  getSnapshot: () => state,
};
vi.mock('../../app/SessionProvider', () => ({
  useSession: () => ({ state, store }),
}));
beforeEach(() => {
  vi.clearAllMocks();
  state = {
    status: 'authenticated',
    busy: false,
    error: null,
    data: {
      user: { id: 'u', email: 'person@company.ru' },
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
  companies = {
    data: [
      { id: 'one', name: 'Первая компания' },
      { id: 'two', name: 'Вторая компания' },
    ],
    meta: { page: 1, pages: 1, per_page: 50, total: 2 },
  };
});
afterEach(cleanup);
it('Continue is disabled without a selected radio and selecting does not POST', async () => {
  await render(<CompanyPicker />);
  const button = [...document.querySelectorAll('button')].find(
    (node) => node.textContent === 'Продолжить',
  );
  expect(button?.disabled).toBe(true);
  const radio = document.querySelector<HTMLInputElement>('input[value="two"]');
  expect(radio?.type).toBe('radio');
  await act(async () => radio?.click());
  expect(button?.disabled).toBe(false);
  expect(selectCompany).not.toHaveBeenCalled();
  await act(async () => button?.click());
  expect(selectCompany).toHaveBeenCalledWith('two');
});
it('preselects the current company only when it is on the visible page', async () => {
  if (state.data) state.data.active_company = companies.data[1] ?? null;
  await render(<CompanyPicker />);
  expect(
    document.querySelector<HTMLInputElement>('input[value="two"]')?.checked,
  ).toBe(true);
  expect(document.body.textContent).not.toContain('ИНН');
});
it('search appears at 8 companies and filters current page accessibly', async () => {
  companies.data = Array.from({ length: 8 }, (_, index) => ({
    id: String(index),
    name: `Компания ${index}`,
  }));
  companies.meta.total = 8;
  await render(<CompanyPicker />);
  const search = document.querySelector<HTMLInputElement>(
    'input[type="search"]',
  );
  expect(search).not.toBeNull();
  await act(async () => {
    Object.getOwnPropertyDescriptor(
      HTMLInputElement.prototype,
      'value',
    )?.set?.call(search, 'Компания 7');
    search?.dispatchEvent(new Event('input', { bubbles: true }));
  });
  expect(document.querySelectorAll('input[type="radio"]')).toHaveLength(1);
});

it('a single company continues without an extra selection mutation', async () => {
  companies.data = companies.data.slice(0, 1);
  companies.meta.total = 1;
  const finish = vi.fn();
  await render(<CompanyPicker onContinue={finish} />);
  expect(finish).toHaveBeenCalledOnce();
  expect(selectCompany).not.toHaveBeenCalled();
  expect(document.querySelector('input[type="radio"]')).toBeNull();
});
