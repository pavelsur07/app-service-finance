import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface EmptyStateProps extends Omit<
  ComponentPropsWithRef<'section'>,
  'title'
> {
  title: ReactNode;
  icon?: ReactNode;
  action?: ReactNode;
  headingLevel?: 1 | 2 | 3;
}
export function EmptyState({
  title,
  icon,
  action,
  headingLevel = 2,
  className = '',
  children,
  ...props
}: EmptyStateProps) {
  const Heading = headingLevel === 1 ? 'h1' : headingLevel === 3 ? 'h3' : 'h2';
  return (
    <section {...props} className={`empty ${className}`}>
      {icon && (
        <span
          aria-hidden="true"
          className="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-surface-subtle text-fg-muted"
        >
          {icon}
        </span>
      )}
      <Heading className="empty-title">{title}</Heading>
      {children && <div className="empty-text">{children}</div>}
      {action && <div className="flex justify-center">{action}</div>}
    </section>
  );
}
