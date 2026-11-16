import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface RadioProps extends Omit<
  ComponentPropsWithRef<'input'>,
  'type' | 'size'
> {
  label: ReactNode;
  error?: boolean;
}
export function Radio({
  label,
  error = false,
  className = '',
  disabled,
  ...props
}: RadioProps) {
  return (
    <label
      className={`inline-flex items-center gap-2 text-md ${disabled ? 'cursor-not-allowed opacity-55' : 'cursor-pointer'} ${className}`}
    >
      <input
        {...props}
        type="radio"
        disabled={disabled}
        aria-invalid={error || props['aria-invalid']}
        className="size-3.5 shrink-0 accent-primary"
      />
      {label}
    </label>
  );
}
