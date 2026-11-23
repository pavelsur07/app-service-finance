import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface CheckboxProps extends Omit<
  ComponentPropsWithRef<'input'>,
  'type' | 'size'
> {
  label: ReactNode;
  error?: boolean;
}
export function Checkbox({
  label,
  error = false,
  className = '',
  disabled,
  ...props
}: CheckboxProps) {
  return (
    <label
      className={`inline-flex items-center gap-2 text-md ${disabled ? 'cursor-not-allowed opacity-55' : 'cursor-pointer'} ${className}`}
    >
      <input
        {...props}
        type="checkbox"
        disabled={disabled}
        aria-invalid={error || props['aria-invalid']}
        className="size-3.5 shrink-0 accent-primary"
      />
      {label}
    </label>
  );
}
