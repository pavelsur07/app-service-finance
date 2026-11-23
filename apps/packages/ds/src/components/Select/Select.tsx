import type { ComponentPropsWithRef } from 'react';
export interface SelectProps extends ComponentPropsWithRef<'select'> {
  error?: boolean;
}
/** Native Select by owner brief; options retain platform keyboard behavior. */
export function Select({
  error = false,
  className = '',
  ...props
}: SelectProps) {
  return (
    <select
      {...props}
      aria-invalid={error || props['aria-invalid']}
      className={`select ${className}`}
    />
  );
}
