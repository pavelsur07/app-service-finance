import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
  AppShell,
  AppHeader,
  Sidebar,
  PageHeader,
  AppFooter,
  type SidebarItem,
} from '../index';

const items: SidebarItem[] = [
  {
    id: 'workspace',
    label: 'Кабинет',
    children: [
      { id: 'home', label: 'Главная', href: '/' },
      { id: 'about', label: 'О приложении', href: '/about' },
    ],
  },
];
function renderShell(collapsed = false, activeId = 'about') {
  return renderToStaticMarkup(
    <AppShell
      collapsed={collapsed}
      onCollapsedChange={() => {}}
      mainId="workspace-main"
      header={
        <AppHeader
          brandLabel="Ваш Финдир"
          breadcrumbs={[
            { label: 'Кабинет', href: '/' },
            { label: 'О приложении' },
          ]}
        />
      }
      sidebar={
        <Sidebar
          items={items}
          activeId={activeId}
          user={{ name: 'person@example.test', detail: 'Клиентский кабинет' }}
          onLogout={() => {}}
        />
      }
      footer={<AppFooter>Ваш Финдир</AppFooter>}
    >
      <PageHeader title="О приложении" description="Описание" />
      <p>Содержимое</p>
    </AppShell>,
  );
}

describe('DS application layout contracts', () => {
  it('renders one main, header, named navigation and footer', () => {
    const html = renderShell();
    expect(html.match(/<main/g)).toHaveLength(1);
    expect(html).toContain('<header');
    expect(html).toContain('<footer');
    expect(html).toContain('aria-label="Основная навигация"');
    expect(html).toContain('data-sidebar="expanded"');
  });
  it('puts a skip link before the header and makes the main focusable', () => {
    const html = renderShell();
    expect(html.indexOf('Перейти к содержимому')).toBeLessThan(
      html.indexOf('<header'),
    );
    expect(html).toContain('href="#workspace-main"');
    expect(html).toContain('id="workspace-main"');
    expect(html).toContain('tabindex="-1"');
  });
  it('marks the active leaf and opens its ancestor', () => {
    const html = renderShell();
    expect(html).toContain('href="/about"');
    expect(html).toMatch(/aria-current="page"[^>]*>[^]*О приложении/);
    expect(html).toContain('aria-expanded="true"');
    expect(html).toContain('aria-controls=');
  });
  it('rail keeps named group controls but hides submenus from interaction', () => {
    const html = renderShell(true);
    expect(html).toContain('data-sidebar="collapsed"');
    expect(html).toContain('Развернуть меню');
    expect(html).toContain('Кабинет');
    expect(html).toContain('aria-expanded="false"');
    expect(html).toMatch(/<ul[^>]*hidden/);
  });
  it('renders identity and native logout without fictional profile links', () => {
    const html = renderShell();
    expect(html).toContain('person@example.test');
    expect(html).toContain('aria-label="Выйти"');
    expect(html).not.toContain('href="#"');
    expect(html).not.toContain('/profile');
  });
  it('supports anonymous composition without a user or logout', () => {
    const html = renderToStaticMarkup(
      <AppShell
        collapsed={false}
        onCollapsedChange={() => {}}
        header={<AppHeader brandLabel="Ваш Финдир" />}
        sidebar={
          <Sidebar items={items} anonymousAction={<a href="/login">Вход</a>} />
        }
      >
        <PageHeader title="О приложении" />
      </AppShell>,
    );
    expect(html).toContain('Вход');
    expect(html).not.toContain('aria-label="Выйти"');
  });
  it('PageHeader provides exactly one page h1 and optional description/actions', () => {
    const html = renderToStaticMarkup(
      <PageHeader
        title="Раздел"
        description="Описание"
        actions={<button>Действие</button>}
      />,
    );
    expect(html.match(/<h1/g)).toHaveLength(1);
    expect(html).toContain('Описание');
    expect(html).toContain('Действие');
  });
  it('current breadcrumb is text and previous breadcrumbs are navigation', () => {
    const html = renderShell();
    expect(html).toContain('aria-label="Хлебные крошки"');
    expect(html).toMatch(/<span[^>]*aria-current="page"/);
  });
  it('does not add unavailable header actions', () => {
    const html = renderShell();
    expect(html).not.toContain('Поиск');
    expect(html).not.toContain('Уведомления');
    expect(html).not.toContain('Выбор периода');
  });
  it('AppFooter retains native attributes', () => {
    expect(
      renderToStaticMarkup(
        <AppFooter aria-label="Информация">Сведения</AppFooter>,
      ),
    ).toContain('aria-label="Информация"');
  });
});
