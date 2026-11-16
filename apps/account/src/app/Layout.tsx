import { Outlet, useLocation } from 'react-router';
import {
  AppShell,
  AppHeader,
  Sidebar,
  AppFooter,
  useSidebarState,
  type SidebarItem,
  type SidebarUser,
  IconHome,
  IconInfoCircle,
  IconUserCircle,
} from '@vashfindir/ds';
import { NavigationLink } from '../shared/NavigationLink';
import { useSession } from './SessionProvider';
import styles from './App.module.css';

const items: readonly SidebarItem[] = [
  {
    id: 'account-workspace',
    label: 'Кабинет',
    icon: <IconUserCircle />,
    children: [
      { id: 'home', label: 'Главная', href: '/', icon: <IconHome /> },
      {
        id: 'about',
        label: 'О приложении',
        href: '/about',
        icon: <IconInfoCircle />,
      },
    ],
  },
];
interface LayoutProps {
  user?: SidebarUser | undefined;
  onLogout?: (() => void) | undefined;
  workspace?: string | undefined;
}
function Layout({ user, onLogout, workspace }: LayoutProps) {
  const sidebar = useSidebarState('vf.account.sidebar');
  const { pathname } = useLocation();
  const title =
    pathname === '/'
      ? 'Клиентский кабинет'
      : pathname === '/about'
        ? 'О приложении'
        : pathname === '/login'
          ? 'Вход'
          : 'Не найдено';
  return (
    <AppShell
      {...sidebar}
      header={
        <AppHeader
          brandLabel="Ваш Финдир"
          breadcrumbs={[{ label: title }]}
          renderLink={(props) => <NavigationLink {...props} />}
        />
      }
      sidebar={
        <Sidebar
          items={items}
          activeId={
            pathname === '/'
              ? 'home'
              : pathname === '/about'
                ? 'about'
                : undefined
          }
          label="Клиентский кабинет"
          user={user}
          onLogout={onLogout}
          workspace={workspace}
          anonymousAction={
            <NavigationLink
              href="/login"
              aria-current={pathname === '/login' ? 'page' : undefined}
            >
              Вход
            </NavigationLink>
          }
          renderLink={(props) => <NavigationLink {...props} />}
        />
      }
      footer={
        <AppFooter>
          <span>Ваш Финдир</span>
          <span className="ml-auto">Клиентский кабинет</span>
        </AppFooter>
      }
    >
      <div className={styles.content}>
        <Outlet />
      </div>
    </AppShell>
  );
}
// Public routes intentionally stay outside SessionProvider: no bootstrap/data calls.
export function PublicLayout() {
  return <Layout />;
}
export function SessionLayout() {
  const { store, state } = useSession();
  return (
    <Layout
      user={
        state.data
          ? { name: state.data.user.email, detail: 'Клиентский кабинет' }
          : undefined
      }
      onLogout={state.data ? () => void store.logout() : undefined}
      workspace={state.data?.active_company?.name}
    />
  );
}
