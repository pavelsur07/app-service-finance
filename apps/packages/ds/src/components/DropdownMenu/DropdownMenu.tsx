import {
  useEffect,
  useId,
  useRef,
  type ComponentPropsWithRef,
  type ReactNode,
} from 'react';
import { Button, type ButtonProps } from '../Button/Button';
export type DropdownMenuItem =
  | { id: string; separator: true }
  | {
      id: string;
      separator?: false;
      label: string;
      onSelect: () => void;
      disabled?: boolean;
      tone?: 'default' | 'primary' | 'danger';
      icon?: ReactNode;
      shortcut?: string;
    };
export interface DropdownMenuProps extends ComponentPropsWithRef<'div'> {
  trigger: string;
  items: readonly DropdownMenuItem[];
  open: boolean;
  onOpenChange: (open: boolean) => void;
  triggerProps?: Omit<
    ButtonProps,
    'children' | 'onClick' | 'onKeyDown' | 'ref'
  >;
}
export function DropdownMenu({
  trigger,
  items,
  open,
  onOpenChange,
  triggerProps,
  className = '',
  ...props
}: DropdownMenuProps) {
  const id = useId();
  const button = useRef<HTMLButtonElement>(null);
  const menu = useRef<HTMLDivElement>(null);
  const initial = useRef<'first' | 'last'>('first');
  const changed = useRef(onOpenChange);
  useEffect(() => {
    changed.current = onOpenChange;
  }, [onOpenChange]);
  useEffect(() => {
    const node = menu.current,
      anchor = button.current;
    if (!open || !node || !anchor) return;
    node.showPopover();
    const place = () => {
      const box = anchor.getBoundingClientRect();
      const width = node.offsetWidth,
        height = node.offsetHeight;
      node.style.left = `${Math.max(8, Math.min(box.right - width, innerWidth - width - 8))}px`;
      node.style.top = `${box.bottom + height + 4 <= innerHeight ? box.bottom + 4 : Math.max(8, box.top - height - 4)}px`;
    };
    place();
    const enabled = [
      ...node.querySelectorAll<HTMLButtonElement>(
        '[role="menuitem"]:not(:disabled)',
      ),
    ];
    (
      (initial.current === 'last' ? enabled.at(-1) : enabled[0]) ?? node
    ).focus();
    const outside = (event: PointerEvent) => {
      if (
        event.target instanceof Node &&
        !node.contains(event.target) &&
        !anchor.contains(event.target)
      )
        changed.current(false);
    };
    const resize = () => changed.current(false);
    document.addEventListener('pointerdown', outside);
    window.addEventListener('resize', resize);
    window.addEventListener('scroll', place, true);
    return () => {
      document.removeEventListener('pointerdown', outside);
      window.removeEventListener('resize', resize);
      window.removeEventListener('scroll', place, true);
      node.hidePopover();
    };
  }, [open]);
  const close = (restore: boolean) => {
    if (restore) button.current?.focus();
    onOpenChange(false);
  };
  const tones = {
    default: 'text-fg-strong hover:bg-surface-page',
    primary: 'font-semibold text-primary-hover hover:bg-primary-tint',
    danger: 'text-danger-text hover:bg-danger-bg',
  };
  return (
    <div {...props} className={`inline-flex ${className}`}>
      <Button
        variant="ghost"
        {...triggerProps}
        ref={button}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? id : undefined}
        onClick={() => {
          initial.current = 'first';
          onOpenChange(!open);
        }}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            initial.current = event.key === 'ArrowUp' ? 'last' : 'first';
            onOpenChange(true);
          }
        }}
      >
        {trigger}
      </Button>
      {open && (
        <div
          ref={menu}
          id={id}
          popover="manual"
          tabIndex={-1}
          role="menu"
          aria-label={trigger}
          className="ds-menu"
          onKeyDown={(event) => {
            const enabled = [
              ...event.currentTarget.querySelectorAll<HTMLButtonElement>(
                '[role="menuitem"]:not(:disabled)',
              ),
            ];
            const index = enabled.findIndex(
              (node) => node === document.activeElement,
            );
            let next: HTMLButtonElement | undefined;
            if (event.key === 'ArrowDown')
              next = enabled[(index + 1) % enabled.length];
            if (event.key === 'ArrowUp')
              next = enabled[(index - 1 + enabled.length) % enabled.length];
            if (event.key === 'Home') next = enabled[0];
            if (event.key === 'End') next = enabled.at(-1);
            if (next) {
              event.preventDefault();
              next.focus();
            }
            if (event.key === 'Escape') {
              event.preventDefault();
              event.stopPropagation();
              close(true);
            }
            if (event.key === 'Tab') close(false);
          }}
        >
          {items.map((item) =>
            item.separator ? (
              <div
                key={item.id}
                role="separator"
                className="my-1 border-t border-line-soft"
              />
            ) : (
              <button
                key={item.id}
                type="button"
                role="menuitem"
                disabled={item.disabled}
                tabIndex={-1}
                onClick={() => {
                  close(true);
                  item.onSelect();
                }}
                className={`flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-md font-medium disabled:opacity-50 ${tones[item.tone ?? 'default']}`}
              >
                {item.icon && <span aria-hidden="true">{item.icon}</span>}
                <span className="flex-1">{item.label}</span>
                {item.shortcut && (
                  <span className="font-mono text-xs text-fg-tertiary">
                    {item.shortcut}
                  </span>
                )}
              </button>
            ),
          )}
        </div>
      )}
    </div>
  );
}
