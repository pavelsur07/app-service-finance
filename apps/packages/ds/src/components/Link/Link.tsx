import type { ComponentPropsWithRef } from 'react';
import { IconExternalLink } from '@tabler/icons-react';

export interface LinkProps extends ComponentPropsWithRef<'a'> {
  variant?: 'inline' | 'standalone' | 'muted';
  external?: boolean;
}
/** DS-Controls; router adapters can intercept the native click. */
export function Link({
  variant = 'inline',
  external = false,
  className = '',
  rel,
  target,
  children,
  ...props
}: LinkProps) {
  const safeRel =
    external || target === '_blank'
      ? [
          ...new Set(`${rel ?? ''} noopener noreferrer`.trim().split(/\s+/)),
        ].join(' ')
      : rel;
  return (
    <a
      {...props}
      target={external ? '_blank' : target}
      rel={safeRel}
      className={`ds-link ds-link-${variant} ${className}`}
    >
      {children}
      {external && (
        <>
          <IconExternalLink size={16} stroke={2} aria-hidden="true" />
          <span className="sr-only"> (в новой вкладке)</span>
        </>
      )}
    </a>
  );
}
