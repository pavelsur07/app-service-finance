import type { ComponentPropsWithRef, ReactNode } from 'react';
import { IconX } from '@tabler/icons-react';
export interface ChipProps extends Omit<
  ComponentPropsWithRef<'span'>,
  'onSelect'
> {
  selected?: boolean;
  disabled?: boolean;
  onSelect?: () => void;
  onRemove?: () => void;
  removeLabel?: string;
  count?: ReactNode;
}
export function Chip({
  selected = false,
  disabled = false,
  onSelect,
  onRemove,
  removeLabel,
  count,
  children,
  className = '',
  ...props
}: ChipProps) {
  const label =
    removeLabel ??
    (typeof children === 'string' ? `Удалить ${children}` : undefined);
  if (onRemove && !label) throw new Error('Chip removal requires removeLabel');
  return (
    <span
      {...props}
      className={`inline-flex items-center gap-1.5 rounded-sm border px-2 py-1 text-sm leading-[1.4] whitespace-nowrap ${selected ? 'border-primary-border bg-primary-tint font-bold text-primary-hover' : 'border-line bg-surface-subtle font-semibold text-fg-secondary'} ${className}`}
    >
      {onSelect ? (
        <button
          type="button"
          disabled={disabled}
          aria-pressed={selected}
          onClick={onSelect}
          className="rounded-xs"
        >
          {children}
        </button>
      ) : (
        children
      )}
      {count != null && (
        <span className="ml-0.5 text-fg-tertiary">{count}</span>
      )}
      {onRemove && (
        <button
          type="button"
          disabled={disabled}
          aria-label={label}
          onClick={onRemove}
          className="inline-flex size-4.5 items-center justify-center rounded-sm hover:bg-surface-subtle-hover disabled:opacity-50"
        >
          <IconX size={16} stroke={2} aria-hidden="true" />
        </button>
      )}
    </span>
  );
}
