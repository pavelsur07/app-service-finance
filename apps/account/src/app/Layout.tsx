import { Outlet, useLocation, useOutletContext } from 'react-router';
import {
  Alert,
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

export const ACCOUNT_SIDEBAR_STORAGE_KEY = 'vf.account.sidebar';
type SidebarState = ReturnType<typeof useSidebarState>;

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
  sidebar: SidebarState;
  user?: SidebarUser | undefined;
  onLogout?: (() => void) | undefined;
  workspace?: string | undefined;
  sessionError?: string | null;
}
function Layout({
  sidebar,
  user,
  onLogout,
  workspace,
  sessionError,
}: LayoutProps) {
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
        {sessionError && (
          <Alert tone="danger" className="mb-4">
            {sessionError}
          </Alert>
        )}
        <Outlet />
      </div>
    </AppShell>
  );
}
// Public routes intentionally stay outside SessionProvider: no bootstrap/data calls.
export function PublicLayout() {
  const sidebar = useSidebarState(ACCOUNT_SIDEBAR_STORAGE_KEY);
  return <Layout sidebar={sidebar} />;
}
export function SessionLayout() {
  const { store, state } = useSession();
  const sidebar = useOutletContext<SidebarState>();
  return (
    <Layout
      sidebar={sidebar}
      user={
        state.data
          ? { name: state.data.user.email, detail: 'Клиентский кабинет' }
          : undefined
      }
      onLogout={state.data ? () => void store.logout() : undefined}
      workspace={state.data?.active_company?.name}
      sessionError={state.error}
    />
  );
}
