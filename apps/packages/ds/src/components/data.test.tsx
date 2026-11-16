import { renderToStaticMarkup as render } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
  Chip,
  AbcPill,
  MarketplaceMark,
  Avatar,
  TagsInput,
  KpiCard,
  Table,
  SortHeader,
  TotalsRow,
  Pagination,
  Tabs,
  Modal,
  Drawer,
  ConfirmDialog,
  DropdownMenu,
  EntityPicker,
  TreePicker,
} from '../index';

describe('Stage5 semantic/native SSR contracts', () => {
  it('keeps chip selection and removal as distinct native buttons', () => {
    const html = render(
      <Chip selected onSelect={() => {}} onRemove={() => {}}>
        Проект
      </Chip>,
    );
    expect(html).toContain('aria-pressed="true"');
    expect(html).toContain('aria-label="Удалить Проект"');
    expect(html.match(/<button/g)).toHaveLength(2);
    expect(html).not.toMatch(/<button[^>]*>[^<]*<button/);
  });
  it.each(['A', 'B', 'C'] as const)(
    'renders ABC %s with a meaningful label',
    (revenue) => {
      expect(render(<AbcPill revenue={revenue} profit="B" />)).toContain(
        `Выручка ${revenue}, прибыль B`,
      );
    },
  );
  it.each(['ozon', 'wb', 'yamarket', 'megamarket'] as const)(
    'marketplace %s has accessible identity',
    (marketplace) =>
      expect(render(<MarketplaceMark marketplace={marketplace} />)).toContain(
        'role="img"',
      ),
  );
  it('avatar has text fallback and declared sizes', () =>
    expect(
      render(<Avatar name="Павел Иванов" size="lg" shape="square" />),
    ).toContain('ПИ'));
  it('tags input keeps a label/native input and disables all mutations', () => {
    const html = render(
      <TagsInput
        label="Метки"
        value={['Первая']}
        onChange={() => {}}
        disabled
      />,
    );
    expect(html).toContain('Метки');
    expect(html).toContain('disabled=""');
    expect(html).toContain('Удалить Первая');
  });
  it('required tags serialize committed values, not editor text', () => {
    const html = render(
      <TagsInput
        label="Метки"
        name="tags"
        required
        value={['Готовая']}
        onChange={() => {}}
      />,
    );
    expect(html).toContain('aria-required="true"');
    expect(html).toContain('type="hidden" name="tags"');
    expect(html).toContain('value="Готовая"');
    expect(html).not.toContain(' required=""');
  });
  it('KPI leaves the main value neutral and colors only a caller-supplied delta', () => {
    const html = render(
      <KpiCard
        label="Расходы"
        value="−1 000 ₽"
        delta={{ value: '−4,1%', tone: 'down' }}
      />,
    );
    expect(html).toContain('−1 000 ₽');
    expect(html).toContain('ds-kpi-value');
    expect(html).toContain('−4,1%');
  });
  it('table uses native sections and aria-sort on th, never calculates totals', () => {
    const html = render(
      <Table caption="Операции">
        <thead>
          <tr>
            <SortHeader direction="descending" onSort={() => {}}>
              Сумма
            </SortHeader>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>12</td>
          </tr>
        </tbody>
        <tfoot>
          <TotalsRow>
            <th scope="row">Итого</th>
            <td>12</td>
          </TotalsRow>
        </tfoot>
      </Table>,
    );
    expect(html).toContain('<caption');
    expect(html).toMatch(/<th[^>]*aria-sort="descending"/);
    expect(html).toContain('<tfoot');
    expect(html).toContain('type="button"');
  });
  it('pagination has an empty range and no invalid navigation', () => {
    const html = render(
      <Pagination page={1} pageSize={25} total={0} onPageChange={() => {}} />,
    );
    expect(html).toContain('0–0');
    expect(html).toContain('disabled=""');
  });
  it('pagination marks current page and bounds a large page set', () => {
    const html = render(
      <Pagination
        page={50}
        pageSize={10}
        total={1000}
        onPageChange={() => {}}
      />,
    );
    expect(html).toContain('aria-current="page"');
    expect(html).toContain('491–500');
    expect(html.match(/<button/g)?.length).toBeLessThan(12);
  });
  it('tabs associate selected panel and keep disabled tabs out of roving focus', () => {
    const html = render(
      <Tabs
        label="Раздел"
        value="one"
        onChange={() => {}}
        items={[
          { id: 'one', label: 'Первый', content: 'Содержимое' },
          { id: 'two', label: 'Второй', disabled: true },
        ]}
      />,
    );
    expect(html).toContain('role="tablist"');
    expect(html).toContain('role="tabpanel"');
    expect(html).toContain('aria-selected="true"');
    expect(html).toContain('disabled=""');
  });
  it('closed overlays/pickers emit no visible dialog', () => {
    expect(
      render(
        <Modal title="Окно" open={false} onClose={() => {}}>
          Текст
        </Modal>,
      ),
    ).toBe('');
    expect(
      render(<Drawer title="Панель" open={false} onClose={() => {}} />),
    ).toBe('');
    expect(
      render(
        <ConfirmDialog
          title="Подтвердить"
          open={false}
          onClose={() => {}}
          onConfirm={() => {}}
        />,
      ),
    ).toBe('');
    expect(
      render(
        <EntityPicker
          title="Выбор"
          open={false}
          onOpenChange={() => {}}
          value={null}
          onChange={() => {}}
          items={[]}
        />,
      ),
    ).toBe('');
    expect(
      render(
        <TreePicker
          title="Дерево"
          open={false}
          onOpenChange={() => {}}
          value={null}
          onChange={() => {}}
          items={[]}
        />,
      ),
    ).toBe('');
  });
  it('modal markup has named native dialog semantics, no inline open bypass', () => {
    const html = render(
      <Modal title="Окно" open onClose={() => {}}>
        Текст
      </Modal>,
    );
    expect(html).toContain('<dialog');
    expect(html).toContain('aria-modal="true"');
    expect(html).toContain('aria-labelledby=');
    expect(html).not.toContain(' open=');
  });
  it('menu trigger remains a named non-submitting native button', () => {
    const html = render(
      <DropdownMenu
        trigger="Действия"
        open={false}
        onOpenChange={() => {}}
        items={[]}
      />,
    );
    expect(html).toContain('aria-haspopup="menu"');
    expect(html).toContain('aria-expanded="false"');
    expect(html).toContain('type="button"');
  });
});

it('Avatar supports the company picker tint without changing its solid default', () => {
  const html = render(<Avatar name="Первая компания" tone="tint" />);
  expect(html).toContain('bg-primary-tint');
  expect(html).toContain('text-primary');
  expect(render(<Avatar name="Первая компания" />)).toContain(
    'text-fg-inverse',
  );
});
