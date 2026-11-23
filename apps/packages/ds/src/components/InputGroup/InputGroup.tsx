import {
  cloneElement,
  useState,
  type ReactElement,
  type ReactNode,
} from 'react';
import { IconEye, IconEyeOff, IconX } from '@tabler/icons-react';
import { Input, type InputProps } from '../Input/Input';
import { Button, type ButtonProps } from '../Button/Button';

export interface InputGroupProps extends Omit<InputProps, 'prefix'> {
  variant?: 'default' | 'auth';
  prefix?: ReactNode;
  suffix?: ReactNode;
  icon?: ReactNode;
  action?: ReactElement<ButtonProps>;
  clearable?: boolean;
  onClear?: () => void;
}
/** DS-Controls: adornments are decorative; actions have their own names. */
export function InputGroup({
  variant = 'default',
  prefix,
  suffix,
  icon,
  action,
  clearable = false,
  onClear,
  type = 'text',
  value,
  defaultValue,
  disabled,
  readOnly,
  onChange,
  className = '',
  ...props
}: InputGroupProps) {
  const [showPassword, setShowPassword] = useState(false);
  const displayed = value ?? defaultValue ?? '';
  if (clearable && (value === undefined || !onClear))
    throw new Error('Controlled clearable InputGroup requires onClear');
  const canClear =
    clearable && !disabled && !readOnly && String(displayed).length > 0;
  const password = type === 'password';
  const hasRight = Boolean(suffix || action || canClear || password);
  return (
    <div
      className={`ds-input-group ${variant === 'auth' ? 'ds-input-group-auth' : ''}`}
    >
      {(icon || prefix) && (
        <span
          aria-hidden="true"
          className="pointer-events-none inline-flex shrink-0 items-center pl-3 text-fg-muted"
        >
          {icon ?? prefix}
        </span>
      )}
      <Input
        {...props}
        type={password && showPassword ? 'text' : type}
        value={value}
        defaultValue={defaultValue}
        disabled={disabled}
        readOnly={readOnly}
        onChange={onChange}
        className={`ds-group-input ${className}`}
      />
      {hasRight && (
        <span className="inline-flex shrink-0 items-center gap-1 pr-1.5 text-fg-muted">
          {suffix && (
            <span aria-hidden="true" className="pointer-events-none pr-1.5">
              {suffix}
            </span>
          )}
          {canClear && (
            <Button
              variant="ghost"
              size="sm"
              className="ds-input-action"
              iconOnly
              aria-label="Очистить поле"
              icon={<IconX size={16} stroke={2} />}
              onClick={(event) => {
                onClear?.();
                event.currentTarget
                  .closest('div')
                  ?.querySelector('input')
                  ?.focus();
              }}
            />
          )}
          {password && (
            <Button
              variant="ghost"
              size="sm"
              className="ds-input-action"
              iconOnly
              disabled={disabled}
              aria-label={showPassword ? 'Скрыть пароль' : 'Показать пароль'}
              aria-pressed={showPassword}
              icon={
                showPassword ? (
                  <IconEyeOff size={variant === 'auth' ? 18 : 16} stroke={2} />
                ) : (
                  <IconEye size={variant === 'auth' ? 18 : 16} stroke={2} />
                )
              }
              onClick={(event) => {
                setShowPassword((v) => !v);
                event.currentTarget
                  .closest('div')
                  ?.querySelector('input')
                  ?.focus();
              }}
            />
          )}
          {action &&
            cloneElement(action, {
              disabled: disabled || action.props.disabled,
              className: `ds-input-action ${action.props.className ?? ''}`,
            })}
        </span>
      )}
    </div>
  );
}
