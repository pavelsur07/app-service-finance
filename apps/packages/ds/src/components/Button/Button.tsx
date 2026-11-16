import type { ComponentPropsWithRef, ReactNode } from 'react';

export interface ButtonProps extends ComponentPropsWithRef<'button'> {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
  size?: 'sm' | 'md' | 'lg';
  icon?: ReactNode;
  iconOnly?: boolean;
  loading?: boolean;
}

/** DS-Controls: native actions; loading keeps the accessible name. */
export function Button({
  variant = 'primary',
  size = 'md',
  icon,
  iconOnly = false,
  loading = false,
  disabled,
  type = 'button',
  className = '',
  children,
  ...props
}: ButtonProps) {
  if (iconOnly && !props['aria-label']?.trim())
    throw new Error('Button iconOnly requires aria-label');
  return (
    <button
      {...props}
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || props['aria-busy']}
      className={`btn btn-${variant} btn-${size} ${iconOnly ? 'btn-icon' : ''} ${className}`}
    >
      {icon && (
        <span aria-hidden="true" className="inline-flex shrink-0">
          {icon}
        </span>
      )}
      {!iconOnly && children}
    </button>
  );
}
