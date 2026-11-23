import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface AppFooterProps extends ComponentPropsWithRef<'footer'> {
  children: ReactNode;
}
export function AppFooter({ className = '', ...props }: AppFooterProps) {
  return (
    <footer
      {...props}
      className={`flex shrink-0 flex-wrap items-center gap-4 border-t border-line bg-surface-card px-8 py-3 text-sm text-fg-tertiary ${className}`}
    />
  );
}
