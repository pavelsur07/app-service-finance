import {
  useId,
  useRef,
  type ComponentPropsWithRef,
  type ReactNode,
} from 'react';
export interface TabItem {
  id: string;
  label: string;
  count?: ReactNode;
  tone?: 'success' | 'warning' | 'danger';
  disabled?: boolean;
  content?: ReactNode;
}
export interface TabsProps extends Omit<
  ComponentPropsWithRef<'div'>,
  'onChange'
> {
  label: string;
  items: readonly TabItem[];
  value: string;
  onChange: (id: string) => void;
}
export function Tabs({
  label,
  items,
  value,
  onChange,
  className = '',
  ...props
}: TabsProps) {
  const id = useId();
  const refs = useRef<(HTMLButtonElement | null)[]>([]);
  const enabled = items
    .map((item, index) => (item.disabled ? -1 : index))
    .filter((index) => index >= 0);
  const selected = items.findIndex(
    (item) => item.id === value && !item.disabled,
  );
  const active = selected >= 0 ? selected : enabled[0];
  const tones = {
    success: 'bg-success-bg text-success-text',
    warning: 'bg-warning-bg text-warning-text',
    danger: 'bg-danger-bg text-danger-text',
  };
  return (
    <div {...props} className={className}>
      <div
        role="tablist"
        aria-label={label}
        className="inline-flex max-w-full flex-wrap gap-0.5 rounded-md bg-surface-page p-1"
      >
        {items.map((item, index) => (
          <button
            key={item.id}
            ref={(node) => {
              refs.current[index] = node;
            }}
            id={`${id}-tab-${index}`}
            type="button"
            role="tab"
            disabled={item.disabled}
            aria-selected={index === active}
            aria-controls={`${id}-panel-${index}`}
            tabIndex={index === active ? 0 : -1}
            className={`inline-flex h-7.5 items-center gap-1.5 rounded-sm px-3 text-md disabled:opacity-50 ${index === active ? 'bg-surface-card font-bold text-fg-strong shadow-sm' : 'font-semibold text-fg-tertiary'}`}
            onClick={() => onChange(item.id)}
            onKeyDown={(event) => {
              const pos = enabled.indexOf(index);
              let next: number | undefined;
              if (event.key === 'ArrowRight')
                next = enabled[(pos + 1) % enabled.length];
              if (event.key === 'ArrowLeft')
                next = enabled[(pos - 1 + enabled.length) % enabled.length];
              if (event.key === 'Home') next = enabled[0];
              if (event.key === 'End') next = enabled.at(-1);
              if (next != null) {
                event.preventDefault();
                const target = items[next];
                if (target) {
                  onChange(target.id);
                  refs.current[next]?.focus();
                }
              }
            }}
          >
            {item.label}
            {item.count != null && (
              <span
                className={`inline-flex items-center gap-1 text-xs font-semibold ${item.tone ? `rounded-full px-2 py-0.5 ${tones[item.tone]}` : 'text-fg-tertiary'}`}
              >
                {item.tone && (
                  <span
                    aria-hidden="true"
                    className="size-1 rounded-full bg-current"
                  />
                )}
                {item.count}
              </span>
            )}
          </button>
        ))}
      </div>
      {items.map((item, index) => (
        <div
          key={item.id}
          id={`${id}-panel-${index}`}
          role="tabpanel"
          aria-labelledby={`${id}-tab-${index}`}
          hidden={index !== active}
          tabIndex={0}
        >
          {item.content}
        </div>
      ))}
    </div>
  );
}
