import {
  useEffect,
  useRef,
  useState,
  type ComponentPropsWithRef,
  type ReactNode,
} from 'react';
import { IconX } from '@tabler/icons-react';
import { feedbackIcons, type FeedbackTone } from '../Alert/Alert';
import { Button } from '../Button/Button';
export interface ToastProps extends Omit<
  ComponentPropsWithRef<'div'>,
  'title' | 'onClose'
> {
  title: ReactNode;
  tone?: FeedbackTone;
  onClose: () => void;
  duration?: number;
}
/** DS-Feedback: 4s by default; pauses on hover/focus, never takes focus. */
export function Toast({
  title,
  tone = 'success',
  onClose,
  duration = 4000,
  className = '',
  children,
  onMouseEnter,
  onMouseLeave,
  onFocus,
  onBlur,
  ...props
}: ToastProps) {
  const Icon = feedbackIcons[tone];
  const iconColors = {
    success: 'text-success-text',
    danger: 'text-danger-text',
    warning: 'text-warning-text',
    info: 'text-info-text',
  };
  const borders = {
    success: 'border-success-border',
    danger: 'border-danger-border',
    warning: 'border-warning-border',
    info: 'border-info-border',
  };
  const iconBackgrounds = {
    success: 'bg-success-bg',
    danger: 'bg-danger-bg',
    warning: 'bg-warning-bg',
    info: 'bg-info-bg',
  };
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const close = useRef(onClose);
  const remaining = useRef(duration);
  const closed = useRef(false);
  useEffect(() => {
    close.current = onClose;
  }, [onClose]);
  useEffect(() => {
    remaining.current = duration;
    closed.current = false;
  }, [duration]);
  useEffect(() => {
    if (hovered || focused || duration <= 0) return;
    const started = Date.now();
    const timer = setTimeout(() => {
      if (!closed.current) {
        closed.current = true;
        close.current();
      }
    }, remaining.current);
    return () => {
      clearTimeout(timer);
      remaining.current = Math.max(
        0,
        remaining.current - (Date.now() - started),
      );
    };
  }, [hovered, focused, duration]);
  return (
    <div
      {...props}
      className={`flex w-90 max-w-full items-start gap-2 rounded-lg border ${borders[tone]} bg-surface-card px-3.5 py-3 shadow-menu ${className}`}
      onMouseEnter={(event) => {
        setHovered(true);
        onMouseEnter?.(event);
      }}
      onMouseLeave={(event) => {
        setHovered(false);
        onMouseLeave?.(event);
      }}
      onFocus={(event) => {
        setFocused(true);
        onFocus?.(event);
      }}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget))
          setFocused(false);
        onBlur?.(event);
      }}
    >
      <div
        role={tone === 'danger' ? 'alert' : 'status'}
        className="flex min-w-0 flex-1 items-start gap-2.5"
      >
        <span
          aria-hidden="true"
          className={`inline-flex size-5 shrink-0 items-center justify-center rounded-full ${iconBackgrounds[tone]} ${iconColors[tone]}`}
        >
          <Icon size={16} stroke={2} />
        </span>
        <div className="min-w-0">
          <p className="text-md font-semibold text-fg-strong">{title}</p>
          {children && (
            <div className="mt-1 text-md leading-[1.45] text-fg-secondary">
              {children}
            </div>
          )}
        </div>
      </div>
      <Button
        variant="ghost"
        size="sm"
        className="size-5.5 shrink-0 p-0"
        iconOnly
        aria-label="Закрыть уведомление"
        icon={<IconX size={16} stroke={2} />}
        onClick={() => {
          if (!closed.current) {
            closed.current = true;
            onClose();
          }
        }}
      />
    </div>
  );
}
