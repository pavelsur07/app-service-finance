import { Avatar } from '../Avatar/Avatar';
import {
  useEffect,
  useState,
  type ReactNode,
  type ComponentPropsWithRef,
} from 'react';
import {
  IconChevronDown,
  IconLayoutSidebarLeftExpand,
  IconLogout,
  IconPoint,
} from '@tabler/icons-react';
import { Link } from '../Link/Link';
import { Button } from '../Button/Button';
import type { ShellLinkProps } from '../AppHeader/AppHeader';
import { useShell } from '../AppShell/AppShell';
export interface SidebarItem {
  id: string;
  label: string;
  href?: string;
  icon?: ReactNode;
  children?: readonly SidebarItem[];
  disabled?: boolean;
}
export interface SidebarUser {
  name: string;
  detail?: string;
  avatar?: ReactNode;
}
export interface SidebarProps extends Omit<
  ComponentPropsWithRef<'aside'>,
  'id' | 'children'
> {
  items: readonly SidebarItem[];
  activeId?: string | undefined;
  label?: string;
  workspace?: ReactNode;
  user?: SidebarUser | undefined;
  onLogout?: (() => void) | undefined;
  logoutBusy?: boolean;
  anonymousAction?: ReactNode;
  renderLink?: (props: ShellLinkProps) => ReactNode;
}
function activeAncestors(
  items: readonly SidebarItem[],
  activeId?: string,
): string[] {
  return items.flatMap((item) =>
    item.children?.some(
      (child) =>
        child.id === activeId || activeAncestors([child], activeId).length > 0,
    )
      ? [item.id, ...activeAncestors(item.children, activeId)]
      : [],
  );
}
export function Sidebar({
  items,
  activeId,
  label = 'Разделы',
  workspace,
  user,
  onLogout,
  logoutBusy = false,
  anonymousAction,
  renderLink = (props) => <Link {...props} />,
  className: asideClass = '',
  ...props
}: SidebarProps) {
  const { collapsed, changeCollapsed, sidebarId, expandRef } = useShell();
  const [open, setOpen] = useState(
    () => new Set(activeAncestors(items, activeId)),
  );
  useEffect(() => {
    setOpen((previous) => {
      const next = new Set(previous);
      for (const id of activeAncestors(items, activeId)) next.add(id);
      return next.size === previous.size ? previous : next;
    });
  }, [items, activeId]);
  const renderItems = (
    entries: readonly SidebarItem[],
    nested = false,
  ): ReactNode =>
    entries.map((item) => {
      const group = Boolean(item.children?.length);
      const expanded = !collapsed && open.has(item.id);
      const current = activeId === item.id;
      const active =
        current || activeAncestors([item], activeId).includes(item.id);
      const className = `flex w-full min-w-0 items-center rounded-md no-underline hover:no-underline ${nested ? 'px-2.5 py-1.5 text-md' : 'gap-2.5 px-2.5 py-2 text-lg'} ${collapsed ? 'justify-center px-0' : ''} ${current ? 'bg-primary-tint font-bold text-primary-hover' : 'font-medium text-fg-secondary hover:bg-surface-page hover:text-fg-strong'} ${item.disabled ? 'cursor-not-allowed opacity-50' : ''}`;
      return (
        <li key={item.id}>
          {group ? (
            <>
              <button
                type="button"
                disabled={item.disabled}
                aria-expanded={expanded}
                aria-controls={`${sidebarId}-${item.id}`}
                title={collapsed ? item.label : undefined}
                className={className}
                onClick={() => {
                  if (collapsed) changeCollapsed(false, false);
                  setOpen((previous) => {
                    const next = new Set(previous);
                    if (collapsed || !next.has(item.id)) next.add(item.id);
                    else next.delete(item.id);
                    return next;
                  });
                }}
              >
                <span
                  aria-hidden="true"
                  className={`inline-flex shrink-0 ${active ? 'text-primary' : 'text-fg-muted'}`}
                >
                  {item.icon ?? <IconPoint size={18} stroke={2} />}
                </span>
                <span
                  className={
                    collapsed ? 'sr-only' : 'min-w-0 flex-1 truncate text-left'
                  }
                >
                  {item.label}
                </span>
                {!collapsed && (
                  <IconChevronDown
                    size={16}
                    stroke={2}
                    aria-hidden="true"
                    className={`shrink-0 text-fg-muted ${expanded ? '' : '-rotate-90'}`}
                  />
                )}
              </button>
              <ul
                id={`${sidebarId}-${item.id}`}
                hidden={!expanded}
                data-sidebar-submenu
                className="mb-1 ml-(--ds-sb-indent) mr-2 space-y-px border-l border-line-soft pl-3"
              >
                {renderItems(item.children ?? [], true)}
              </ul>
            </>
          ) : item.href ? (
            renderLink({
              href: item.href,
              'aria-current': current ? 'page' : undefined,
              'aria-disabled': item.disabled || undefined,
              tabIndex: item.disabled ? -1 : undefined,
              title: collapsed ? item.label : undefined,
              className,
              onClick: (event) => {
                if (item.disabled) event.preventDefault();
              },
              children: (
                <>
                  {nested ? (
                    <span
                      aria-hidden="true"
                      className="mr-2 size-1.5 shrink-0 rounded-full bg-current"
                    />
                  ) : (
                    <span
                      aria-hidden="true"
                      className="inline-flex shrink-0 text-fg-muted"
                    >
                      {item.icon ?? <IconPoint size={18} stroke={2} />}
                    </span>
                  )}
                  <span className={collapsed ? 'sr-only' : 'min-w-0 truncate'}>
                    {item.label}
                  </span>
                </>
              ),
            })
          ) : (
            <span className={className}>{item.label}</span>
          )}
        </li>
      );
    });
  return (
    <aside
      aria-label="Боковая панель"
      {...props}
      id={sidebarId}
      className={`ds-sidebar-width flex min-h-0 shrink-0 flex-col border-r border-line bg-surface-card ${asideClass}`}
    >
      {workspace && (
        <div className={`px-3 pt-3 pb-1 ${collapsed ? 'sr-only' : ''}`}>
          <div className="rounded-md border border-line bg-surface-subtle px-2.5 py-2 text-md">
            {workspace}
          </div>
        </div>
      )}
      <nav
        aria-label="Основная навигация"
        className={`ds-sidebar-nav ds-scroll min-h-0 flex-1 overflow-y-auto pb-2 ${collapsed ? 'px-2' : 'scroll-stable pr-0.5 pl-2'}`}
      >
        <p
          aria-hidden={collapsed || undefined}
          className={
            collapsed
              ? 'mx-3 my-2 h-px bg-line-soft text-[0px]'
              : 'px-2.5 pt-3 pb-1 text-xs font-bold tracking-caps text-fg-tertiary uppercase'
          }
        >
          {label}
        </p>
        <ul className="space-y-px">{renderItems(items)}</ul>
      </nav>
      <div hidden={!collapsed} className="px-3 pb-2">
        <Button
          ref={expandRef}
          variant="ghost"
          size="sm"
          className="flex w-full p-0"
          iconOnly
          aria-label="Развернуть меню"
          title="Развернуть меню"
          aria-controls={sidebarId}
          aria-expanded={!collapsed}
          icon={<IconLayoutSidebarLeftExpand size={18} stroke={2} />}
          onClick={() => changeCollapsed(false)}
        />
      </div>
      {(user || anonymousAction) && (
        <div
          className={`flex items-center gap-2.5 border-t border-line-soft px-3 py-2.5 ${collapsed ? 'flex-col gap-1.5 px-0' : ''}`}
        >
          {user ? (
            <>
              {user.avatar ? (
                <span
                  aria-hidden="true"
                  className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-tint text-sm font-semibold text-primary"
                >
                  {user.avatar}
                </span>
              ) : (
                <Avatar aria-hidden="true" name={user.name} />
              )}
              <div className={collapsed ? 'sr-only' : 'min-w-0 flex-1'}>
                <p className="truncate text-md font-semibold" title={user.name}>
                  {user.name}
                </p>
                {user.detail && (
                  <p className="truncate text-xs text-fg-muted">
                    {user.detail}
                  </p>
                )}
              </div>
              {onLogout && (
                <Button
                  variant="ghost"
                  size="sm"
                  className="size-8 shrink-0 p-0"
                  iconOnly
                  aria-label="Выйти"
                  title="Выйти"
                  loading={logoutBusy}
                  icon={<IconLogout size={18} stroke={2} />}
                  onClick={onLogout}
                />
              )}
            </>
          ) : (
            anonymousAction
          )}
        </div>
      )}
    </aside>
  );
}
