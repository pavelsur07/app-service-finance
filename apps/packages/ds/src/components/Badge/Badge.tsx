import type { ComponentPropsWithRef, ReactNode } from 'react';
const variants = {
  api: 'bg-(--ds-src-api-bg) text-(--ds-src-api-text) border-(--ds-src-api-border)',
  manual:
    'bg-(--ds-src-manual-bg) text-(--ds-src-manual-text) border-(--ds-src-manual-border)',
  bot: 'bg-(--ds-src-bot-bg) text-(--ds-src-bot-text) border-(--ds-src-bot-border)',
  xls: 'bg-(--ds-src-xls-bg) text-(--ds-src-xls-text) border-(--ds-src-xls-border)',
  '1c': 'bg-(--ds-src-1c-bg) text-(--ds-src-1c-text) border-(--ds-src-1c-border)',
  bank: 'bg-surface-card text-fg-strong border-line',
};
export interface BadgeProps extends ComponentPropsWithRef<'span'> {
  variant?: keyof typeof variants;
  icon?: ReactNode;
}
/** DS-Data: source/bank marks, meaningful text required by the caller. */
export function Badge({
  variant = 'manual',
  icon,
  className = '',
  children,
  ...props
}: BadgeProps) {
  return (
    <span
      {...props}
      className={`inline-flex items-center border font-semibold leading-none ${variant === 'bank' ? 'gap-2.5 rounded-md py-1.5 pr-2.5 pl-1.5 text-md' : 'gap-1 rounded-sm py-0.5 pr-2 pl-1 text-xs'} ${variants[variant]} ${className}`}
    >
      {icon && (
        <span
          aria-hidden="true"
          className={
            variant === 'bank'
              ? 'inline-flex size-8 items-center justify-center rounded-md bg-surface-subtle'
              : 'inline-flex'
          }
        >
          {icon}
        </span>
      )}
      {children}
    </span>
  );
}
