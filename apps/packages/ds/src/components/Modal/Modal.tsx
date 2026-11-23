import {
  useEffect,
  useId,
  useRef,
  type ComponentPropsWithRef,
  type ReactNode,
  type RefObject,
} from 'react';
import { IconX } from '@tabler/icons-react';
import { Button } from '../Button/Button';
interface Lock {
  count: number;
  overflow: string;
  gutter: string;
  restore: HTMLElement | null;
}
const locks = new WeakMap<Document, Lock>();
const opening = new Map<HTMLDialogElement, () => void>();
let flushQueued = false;
/** React effects run child-first; native top-layer opening must run parent-first. */
function scheduleOpening(
  node: HTMLDialogElement,
  start: () => void,
): () => void {
  opening.set(node, start);
  if (!flushQueued) {
    flushQueued = true;
    queueMicrotask(() => {
      flushQueued = false;
      const batch = new Map(opening);
      opening.clear();
      const opened = new Set<HTMLDialogElement>();
      const visit = (dialog: HTMLDialogElement) => {
        if (opened.has(dialog)) return;
        let parent = dialog.parentElement;
        while (parent) {
          if (parent instanceof HTMLDialogElement && batch.has(parent)) {
            visit(parent);
            break;
          }
          parent = parent.parentElement;
        }
        opened.add(dialog);
        batch.get(dialog)?.();
      };
      for (const dialog of batch.keys()) visit(dialog);
    });
  }
  return () => {
    if (opening.get(node) === start) opening.delete(node);
  };
}
function lockScroll(
  doc: Document,
  restore: HTMLElement | null,
): () => HTMLElement | null {
  let lock = locks.get(doc);
  if (!lock) {
    lock = {
      count: 0,
      overflow: doc.body.style.overflow,
      gutter: doc.documentElement.style.scrollbarGutter,
      restore,
    };
    locks.set(doc, lock);
    if ((doc.defaultView?.innerWidth ?? 0) > doc.documentElement.clientWidth)
      doc.documentElement.style.scrollbarGutter = 'stable';
    doc.body.style.overflow = 'hidden';
  }
  lock.count++;
  let released = false;
  return () => {
    if (released) return null;
    released = true;
    lock.count--;
    if (lock.count) return null;
    doc.body.style.overflow = lock.overflow;
    doc.documentElement.style.scrollbarGutter = lock.gutter;
    locks.delete(doc);
    return lock.restore;
  };
}
export interface ModalProps extends Omit<
  ComponentPropsWithRef<'dialog'>,
  'open' | 'title' | 'onClose'
> {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  footer?: ReactNode;
  /** Active-window notifications stay inside the native top layer and focus boundary. */
  notifications?: ReactNode;
  size?: 'sm' | 'md' | 'lg';
  initialFocusRef?: RefObject<HTMLElement | null> | undefined;
  dismissible?: boolean;
  closeOnBackdrop?: boolean;
  showClose?: boolean;
}
interface OverlayProps extends ModalProps {
  drawer?: boolean;
}
/** Native top layer supplies inert background without portals or another runtime dependency. */
export function Overlay({
  open,
  onClose,
  title,
  description,
  footer,
  notifications,
  size = 'md',
  initialFocusRef,
  dismissible = true,
  closeOnBackdrop = true,
  showClose = true,
  drawer = false,
  children,
  className = '',
  ref: forwarded,
  onCancel,
  onKeyDown,
  onPointerDown,
  onClick,
  ...props
}: OverlayProps) {
  const id = useId();
  const dialog = useRef<HTMLDialogElement>(null);
  const backdropStart = useRef(false);
  useEffect(() => {
    const node = dialog.current;
    if (!open || !node) return;
    const doc = node.ownerDocument;
    let cancelled = false;
    let previous: HTMLElement | null = null;
    let release: (() => HTMLElement | null) | undefined;
    const cancelOpening = scheduleOpening(node, () => {
      if (cancelled || !node.isConnected) return;
      previous =
        doc.activeElement instanceof HTMLElement ? doc.activeElement : null;
      release = lockScroll(doc, previous);
      try {
        node.showModal();
      } catch (error) {
        release();
        release = undefined;
        throw error;
      }
      if (initialFocusRef?.current && node.contains(initialFocusRef.current))
        initialFocusRef.current.focus({ preventScroll: true });
    });
    return () => {
      cancelled = true;
      cancelOpening();
      if (node.open) node.close();
      const target = release?.() ?? previous;
      if (target?.isConnected) target.focus({ preventScroll: true });
    };
  }, [open, initialFocusRef]);
  if (!open) return null;
  const outside = (node: HTMLDialogElement, x: number, y: number) => {
    const box = node.getBoundingClientRect();
    return x < box.left || x > box.right || y < box.top || y > box.bottom;
  };
  return (
    <dialog
      {...props}
      ref={(node) => {
        dialog.current = node;
        if (typeof forwarded === 'function') return forwarded(node);
        if (forwarded) forwarded.current = node;
      }}
      role="dialog"
      aria-modal="true"
      aria-labelledby={`${id}-title`}
      aria-describedby={
        [
          props['aria-describedby'],
          description ? `${id}-description` : undefined,
        ]
          .filter(Boolean)
          .join(' ') || undefined
      }
      tabIndex={-1}
      data-size={size}
      className={`ds-dialog ${drawer ? 'ds-drawer' : ''} ${className}`}
      onCancel={(event) => {
        onCancel?.(event);
        const cancelled = event.defaultPrevented;
        event.preventDefault();
        if (!cancelled && dismissible) onClose();
      }}
      onClose={() => {
        if (dismissible) onClose();
      }}
      onPointerDown={(event) => {
        onPointerDown?.(event);
        backdropStart.current =
          !event.defaultPrevented &&
          event.target === event.currentTarget &&
          outside(event.currentTarget, event.clientX, event.clientY);
      }}
      onClick={(event) => {
        onClick?.(event);
        if (
          !event.defaultPrevented &&
          backdropStart.current &&
          event.target === event.currentTarget &&
          outside(event.currentTarget, event.clientX, event.clientY) &&
          dismissible &&
          closeOnBackdrop
        )
          onClose();
        backdropStart.current = false;
      }}
      onKeyDown={(event) => {
        onKeyDown?.(event);
        if (
          event.defaultPrevented ||
          event.key !== 'Tab' ||
          (event.target instanceof Element &&
            event.target.closest('dialog') !== event.currentTarget)
        )
          return;
        const focusable = [
          ...event.currentTarget.querySelectorAll<HTMLElement>(
            'a[href],button,input,select,textarea,[tabindex]',
          ),
        ].filter(
          (node) =>
            node.tabIndex >= 0 &&
            !node.matches(':disabled') &&
            node.getClientRects().length > 0 &&
            !node.closest('[inert]'),
        );
        const first = focusable[0],
          last = focusable.at(-1),
          active = event.currentTarget.ownerDocument.activeElement;
        if (!first) {
          event.preventDefault();
          event.currentTarget.focus();
        } else if (
          event.shiftKey &&
          (active === first || active === event.currentTarget)
        ) {
          event.preventDefault();
          last?.focus();
        } else if (
          !event.shiftKey &&
          (active === last || active === event.currentTarget)
        ) {
          event.preventDefault();
          first.focus();
        }
      }}
    >
      <header className="ds-dialog-head">
        <div className="min-w-0 flex-1">
          <h2
            id={`${id}-title`}
            className="font-bold tracking-[-.015em] text-fg-strong"
          >
            {title}
          </h2>
          {description && (
            <div
              id={`${id}-description`}
              className="mt-1 text-sm text-fg-tertiary"
            >
              {description}
            </div>
          )}
        </div>
        {showClose && (
          <Button
            variant="ghost"
            iconOnly
            aria-label={drawer ? 'Закрыть панель' : 'Закрыть окно'}
            icon={<IconX size={18} stroke={2} />}
            size="sm"
            disabled={!dismissible}
            onClick={onClose}
          />
        )}
      </header>
      <div className="ds-dialog-body ds-scroll scroll-stable">{children}</div>
      {footer && <footer className="ds-dialog-foot">{footer}</footer>}
      {notifications}
    </dialog>
  );
}
export function Modal(props: ModalProps) {
  return <Overlay {...props} />;
}
