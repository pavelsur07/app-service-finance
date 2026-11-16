import type { ComponentPropsWithRef } from 'react';
export interface InputProps extends ComponentPropsWithRef<'input'> {
  error?: boolean;
}
/** DS-Controls; React 19 ref and all native attributes are retained. */
export function Input({ error = false, className = '', ...props }: InputProps) {
  return (
    <input
      {...props}
      aria-invalid={error || props['aria-invalid']}
      className={`input ${className}`}
    />
  );
}
