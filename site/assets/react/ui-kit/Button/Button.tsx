import { cn } from '@/react/shared/lib/cn';
import type { ButtonHTMLAttributes, FC, ReactNode } from 'react';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-solid' | 'warning-solid';
export type ButtonSize = 'sm' | 'md' | 'lg';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  loading?: boolean;
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
}

/**
 * Button — типизированная обёртка над UI Kit `.btn` классами.
 *
 * Не пишет свой CSS. Все стили из ui-kit/components/button.css,
 * подключённого через assets/styles/app.css.
 *
 * @uiKit ui-kit/components/button.html
 * @version 1.4
 *
 * @example
 *   <Button variant="primary" size="md" onClick={save}>Сохранить</Button>
 *   <Button variant="secondary" size="sm" disabled>Отмена</Button>
 *   <Button variant="primary" loading>Сохранение…</Button>
 */
export const Button: FC<ButtonProps> = ({
  variant = 'primary',
  size = 'md',
  loading = false,
  leadingIcon,
  trailingIcon,
  className,
  disabled,
  children,
  type = 'button',
  ...rest
}) => (
  <button
    type={type}
    className={cn(
      'btn',
      `btn-${variant}`,
      `btn-${size}`,
      loading && 'btn-loading',
      className,
    )}
    disabled={disabled || loading}
    aria-busy={loading || undefined}
    {...rest}
  >
    {leadingIcon}
    {children}
    {trailingIcon}
  </button>
);
