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
  IconShield,
} from '@vashfindir/ds';
import { NavigationLink } from '../shared/NavigationLink';
import { useSession } from './SessionProvider';
import styles from './App.module.css';

const items: readonly SidebarItem[] = [
  {
    id: 'admin-workspace',
    label: 'Администрирование',
    icon: <IconShield />,
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
  const sidebar = useSidebarState('vf.admin.sidebar');
  const { pathname } = useLocation();
  const title =
    pathname === '/'
      ? 'Административный интерфейс'
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
          label="Административный интерфейс"
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
          <span className="ml-auto">Административный интерфейс</span>
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
          ? {
              name: state.data.user.email,
              detail: 'Административный интерфейс',
            }
          : undefined
      }
      onLogout={state.data ? () => void store.logout() : undefined}
    />
  );
}
