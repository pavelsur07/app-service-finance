import type { ReactNode, ComponentPropsWithRef } from 'react';
export interface PageHeaderProps extends Omit<
  ComponentPropsWithRef<'div'>,
  'title' | 'children'
> {
  title: ReactNode;
  description?: ReactNode;
  actions?: ReactNode;
}
export function PageHeader({
  title,
  description,
  actions,
  className = '',
  ...props
}: PageHeaderProps) {
  return (
    <div
      {...props}
      className={`mb-5 flex flex-wrap items-start justify-between gap-3 ${className}`}
    >
      <div className="min-w-0">
        <h1 className="wz-title">{title}</h1>
        {description && (
          <p className="mt-1 text-md text-fg-tertiary">{description}</p>
        )}
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-2">{actions}</div>
      )}
    </div>
  );
}
