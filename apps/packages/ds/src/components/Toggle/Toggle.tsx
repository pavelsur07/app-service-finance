import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface ToggleProps extends Omit<
  ComponentPropsWithRef<'input'>,
  'type' | 'size'
> {
  label: ReactNode;
}
export function Toggle({
  label,
  className = '',
  disabled,
  ...props
}: ToggleProps) {
  return (
    <label
      className={`inline-flex items-center gap-2.5 text-md ${disabled ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'} ${className}`}
    >
      <span className="relative inline-flex shrink-0">
        <input
          {...props}
          disabled={disabled}
          type="checkbox"
          role="switch"
          className="peer absolute inset-0 z-raised m-0 h-6 w-10.5 cursor-inherit opacity-0"
        />
        <span
          aria-hidden="true"
          className="ds-toggle-track h-6 w-10.5 rounded-full border border-line-strong bg-line-strong peer-checked:border-primary peer-checked:bg-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--ds-focus-ring-color)"
        >
          <span className="ds-toggle-knob absolute top-0.5 left-0.5 size-5 rounded-full bg-fg-inverse shadow-sm" />
        </span>
      </span>
      {label}
    </label>
  );
}
