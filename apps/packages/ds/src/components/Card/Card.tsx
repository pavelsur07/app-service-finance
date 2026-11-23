import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface CardProps extends Omit<
  ComponentPropsWithRef<'section'>,
  'title'
> {
  title?: ReactNode;
  actions?: ReactNode;
  flush?: boolean;
}
/** DS-Data: Card does not calculate data or impose a page heading. */
export function Card({
  title,
  actions,
  flush = false,
  className = '',
  children,
  ...props
}: CardProps) {
  return (
    <section {...props} className={`card ${flush ? 'p-0' : ''} ${className}`}>
      {(title || actions) && (
        <header
          className={`flex items-center justify-between gap-3 ${flush ? 'border-b border-line px-5 py-4' : 'mb-3'}`}
        >
          {title && (
            <h2 className="text-xs font-bold tracking-caps text-fg-tertiary uppercase">
              {title}
            </h2>
          )}
          {actions}
        </header>
      )}
      {children}
    </section>
  );
}
