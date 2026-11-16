import { Children, type ReactNode, type ComponentPropsWithRef } from 'react';

export interface ToastStackProps extends ComponentPropsWithRef<'section'> {
  children: ReactNode;
}
/** DS-Feedback: mount once at the app root, outside transformed/scrolling panels. */
export function ToastStack({
  children,
  className = '',
  ...props
}: ToastStackProps) {
  if (Children.toArray(children).length === 0) return null;
  return (
    <section
      aria-label="Уведомления"
      {...props}
      className={`pointer-events-none fixed top-6 right-6 z-toast flex w-90 max-w-[calc(100vw-48px)] flex-col gap-2.5 [&>*]:pointer-events-auto ${className}`}
    >
      {children}
    </section>
  );
}
