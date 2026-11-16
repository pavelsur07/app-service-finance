import type { ComponentPropsWithRef } from 'react';
export interface TextareaProps extends ComponentPropsWithRef<'textarea'> {
  error?: boolean;
}
export function Textarea({
  error = false,
  className = '',
  ...props
}: TextareaProps) {
  return (
    <textarea
      {...props}
      aria-invalid={error || props['aria-invalid']}
      className={`input ds-textarea ${className}`}
    />
  );
}
