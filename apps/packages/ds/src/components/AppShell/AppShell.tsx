import {
  createContext,
  useContext,
  useEffect,
  useId,
  useRef,
  type ReactNode,
  type RefObject,
  type ComponentPropsWithRef,
} from 'react';

interface ShellContextValue {
  collapsed: boolean;
  sidebarId: string;
  collapseRef: RefObject<HTMLButtonElement | null>;
  expandRef: RefObject<HTMLButtonElement | null>;
  changeCollapsed: (value: boolean, restoreFocus?: boolean) => void;
}
const ShellContext = createContext<ShellContextValue | null>(null);
export function useShell(): ShellContextValue {
  const value = useContext(ShellContext);
  if (!value) throw new Error('AppHeader/Sidebar require AppShell');
  return value;
}
export interface AppShellProps extends Omit<
  ComponentPropsWithRef<'div'>,
  'children'
> {
  collapsed: boolean;
  onCollapsedChange: (collapsed: boolean) => void;
  header: ReactNode;
  sidebar: ReactNode;
  footer?: ReactNode;
  mainId?: string;
  children: ReactNode;
}
/** DS App-Layout: viewport owns the shell; main and navigation scroll separately. */
export function AppShell({
  collapsed,
  onCollapsedChange,
  header,
  sidebar,
  footer,
  mainId = 'main',
  children,
  className = '',
  onFocusCapture,
  ...props
}: AppShellProps) {
  const id = useId();
  const sidebarId = `sidebar-${id}`;
  const collapseRef = useRef<HTMLButtonElement>(null);
  const expandRef = useRef<HTMLButtonElement>(null);
  const mainRef = useRef<HTMLElement>(null);
  const lastFocus = useRef<HTMLElement>(null);
  const pendingFocus = useRef(false);
  useEffect(() => {
    if (
      pendingFocus.current ||
      (collapsed &&
        (lastFocus.current === collapseRef.current ||
          lastFocus.current?.closest('[data-sidebar-submenu]'))) ||
      (!collapsed && lastFocus.current === expandRef.current)
    ) {
      (collapsed ? expandRef.current : collapseRef.current)?.focus();
      pendingFocus.current = false;
    }
  }, [collapsed]);
  const value: ShellContextValue = {
    collapsed,
    sidebarId,
    collapseRef,
    expandRef,
    changeCollapsed: (next, restoreFocus = true) => {
      pendingFocus.current = restoreFocus;
      onCollapsedChange(next);
    },
  };
  return (
    <ShellContext.Provider value={value}>
      <div
        {...props}
        data-sidebar={collapsed ? 'collapsed' : 'expanded'}
        className={`ds-app-shell flex h-dvh min-w-0 flex-col overflow-hidden ${className}`}
        onFocusCapture={(event) => {
          if (event.target instanceof HTMLElement)
            lastFocus.current = event.target;
          onFocusCapture?.(event);
        }}
      >
        <a
          href={`#${mainId}`}
          className="fixed top-3 left-4 z-toast -translate-y-24 rounded-md bg-primary px-4 py-2 text-fg-inverse focus:translate-y-0"
          onClick={(event) => {
            event.preventDefault();
            mainRef.current?.focus();
          }}
        >
          Перейти к содержимому
        </a>
        {header}
        <div className="flex min-h-0 flex-1">
          {sidebar}
          <main
            ref={mainRef}
            id={mainId}
            tabIndex={-1}
            className="ds-workspace ds-scroll scroll-stable min-w-0 flex-1 overflow-y-auto focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-(--ds-focus-ring-color)"
          >
            <div className="flex min-h-full min-w-0 flex-col">
              <div className="ds-page-content min-w-0 flex-1 px-8 py-6">
                {children}
              </div>
              {footer}
            </div>
          </main>
        </div>
      </div>
    </ShellContext.Provider>
  );
}
