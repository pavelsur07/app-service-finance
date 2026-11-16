import type { ReactNode, ComponentPropsWithRef } from 'react';
import {
  IconChevronRight,
  IconLayoutSidebarLeftCollapse,
} from '@tabler/icons-react';
import { Button } from '../Button/Button';
import { Link, type LinkProps } from '../Link/Link';
import { useShell } from '../AppShell/AppShell';
export interface ShellLinkProps extends LinkProps {
  href: string;
}
export interface BreadcrumbItem {
  label: ReactNode;
  href?: string;
}
export interface AppHeaderProps extends Omit<
  ComponentPropsWithRef<'header'>,
  'children'
> {
  brandLabel: string;
  brandHref?: string;
  breadcrumbs?: readonly BreadcrumbItem[];
  actions?: ReactNode;
  period?: ReactNode;
  search?: ReactNode;
  notifications?: ReactNode;
  renderLink?: (props: ShellLinkProps) => ReactNode;
}
export function AppHeader({
  brandLabel,
  brandHref = '/',
  breadcrumbs = [],
  actions,
  period,
  search,
  notifications,
  renderLink = (props) => <Link {...props} />,
  className = '',
  ...props
}: AppHeaderProps) {
  const { collapsed, changeCollapsed, sidebarId, collapseRef } = useShell();
  return (
    <header
      {...props}
      className={`ds-app-header z-header flex h-header shrink-0 border-b border-(--ds-app-header-border) bg-(--ds-app-header-bg) ${className}`}
    >
      <div
        className={`ds-sidebar-width flex shrink-0 items-center gap-2.5 border-r border-line ${collapsed ? 'justify-center px-0' : 'px-4'}`}
      >
        {renderLink({
          href: brandHref,
          className: 'min-w-0 shrink-0 gap-2.5 no-underline hover:no-underline',
          children: (
            <>
              <span
                aria-hidden="true"
                className="flex size-8 shrink-0 items-center justify-center rounded-md bg-fg-strong text-md font-extrabold text-fg-inverse"
              >
                ВФ
              </span>
              <span
                className={
                  collapsed ? 'sr-only' : 'text-lg font-bold text-primary'
                }
              >
                {brandLabel}
              </span>
            </>
          ),
        })}
        <Button
          ref={collapseRef}
          hidden={collapsed}
          variant="ghost"
          size="sm"
          className="ml-auto size-8 shrink-0 p-0"
          aria-label="Свернуть меню"
          title="Свернуть меню"
          aria-controls={sidebarId}
          aria-expanded={!collapsed}
          iconOnly
          icon={<IconLayoutSidebarLeftCollapse size={18} stroke={2} />}
          onClick={() => changeCollapsed(true)}
        />
      </div>
      <div className="flex min-w-0 flex-1 items-center gap-3.5 px-(--ds-app-header-gutter)">
        {breadcrumbs.length > 0 && (
          <nav aria-label="Хлебные крошки" className="min-w-0 flex-1">
            <ol className="flex min-w-0 items-center gap-1.5 text-lg">
              {breadcrumbs.map((item, index) => (
                <li key={index} className="flex min-w-0 items-center gap-1.5">
                  {index > 0 && (
                    <IconChevronRight
                      size={16}
                      stroke={2}
                      aria-hidden="true"
                      className="shrink-0 text-fg-muted"
                    />
                  )}
                  {index === breadcrumbs.length - 1 ? (
                    <span
                      aria-current="page"
                      className="truncate font-bold text-fg-strong"
                    >
                      {item.label}
                    </span>
                  ) : item.href ? (
                    renderLink({
                      href: item.href,
                      className:
                        'truncate font-medium text-fg-tertiary no-underline hover:text-primary hover:no-underline',
                      children: item.label,
                    })
                  ) : (
                    <span className="truncate text-fg-tertiary">
                      {item.label}
                    </span>
                  )}
                </li>
              ))}
            </ol>
          </nav>
        )}
        {(period || search || notifications || actions) && (
          <div className="ml-auto flex shrink-0 items-center gap-2">
            {period}
            {search}
            {notifications}
            {actions}
          </div>
        )}
      </div>
    </header>
  );
}
