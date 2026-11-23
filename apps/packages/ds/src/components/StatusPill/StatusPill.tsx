import type { ComponentPropsWithRef } from 'react';
const tones = {
  success:
    'bg-(--ds-status-success-bg) text-(--ds-status-success-text) [&>span]:bg-(--ds-status-success-dot)',
  danger:
    'bg-(--ds-status-danger-bg) text-(--ds-status-danger-text) [&>span]:bg-(--ds-status-danger-dot)',
  warning:
    'bg-(--ds-status-warning-bg) text-(--ds-status-warning-text) [&>span]:bg-(--ds-status-warning-dot)',
  neutral:
    'bg-(--ds-status-neutral-bg) text-(--ds-status-neutral-text) [&>span]:bg-(--ds-status-neutral-dot)',
};
export interface StatusPillProps extends ComponentPropsWithRef<'span'> {
  tone?: keyof typeof tones;
}
export function StatusPill({
  tone = 'neutral',
  className = '',
  children,
  ...props
}: StatusPillProps) {
  return (
    <span
      {...props}
      className={`inline-flex items-center gap-1.5 rounded-full py-1 pr-2.5 pl-2 text-sm font-semibold leading-none ${tones[tone]} ${className}`}
    >
      <span aria-hidden="true" className="size-1.5 shrink-0 rounded-full" />
      {children}
    </span>
  );
}
