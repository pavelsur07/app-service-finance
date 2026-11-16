import type { ComponentPropsWithRef, ReactNode } from 'react';
export interface KpiCardProps extends Omit<
  ComponentPropsWithRef<'section'>,
  'title'
> {
  label: ReactNode;
  value: ReactNode;
  delta?: { value: ReactNode; tone: 'up' | 'down' | 'neutral' };
  meta?: ReactNode;
  hero?: boolean;
  status?: ReactNode;
}
export function KpiCard({
  label,
  value,
  delta,
  meta,
  hero = false,
  status,
  className = '',
  ...props
}: KpiCardProps) {
  const tones = {
    up: 'bg-success-bg text-success-text',
    down: 'bg-danger-bg text-danger-text',
    neutral: 'bg-surface-page text-fg-secondary',
  };
  return (
    <section
      {...props}
      className={`flex min-w-0 flex-col gap-1.5 rounded-lg border border-line px-5 py-4 ${hero ? 'bg-primary' : 'bg-surface-card'} ${className}`}
    >
      <div className="flex items-center justify-between gap-2">
        <h2
          className={`truncate text-sm font-semibold tracking-[.02em] ${hero ? 'text-fg-disabled' : 'text-fg-tertiary'}`}
        >
          {label}
        </h2>
        {status}
      </div>
      <div
        className={`ds-kpi-value font-bold tracking-[-.02em] tabular-nums ${hero ? 'text-4xl text-fg-inverse' : 'text-3xl text-fg-strong'}`}
      >
        {value}
      </div>
      {(delta || meta) && (
        <div
          className={`flex flex-wrap items-center gap-1.5 text-xs ${hero ? 'text-fg-disabled' : 'text-fg-tertiary'}`}
        >
          {delta && (
            <span
              className={`rounded-sm px-2 py-0.5 font-bold tabular-nums ${tones[delta.tone]}`}
            >
              {delta.value}
            </span>
          )}
          {meta}
        </div>
      )}
    </section>
  );
}
