import type { ComponentPropsWithRef } from 'react';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-react';
export interface PaginationProps extends Omit<
  ComponentPropsWithRef<'nav'>,
  'onChange'
> {
  page: number;
  pageSize: number;
  total: number;
  onPageChange: (page: number) => void;
}
export function Pagination({
  page,
  pageSize,
  total,
  onPageChange,
  className = '',
  ...props
}: PaginationProps) {
  if (
    !Number.isSafeInteger(pageSize) ||
    pageSize < 1 ||
    !Number.isSafeInteger(total) ||
    total < 0 ||
    !Number.isFinite(page)
  )
    throw new RangeError('Invalid pagination bounds');
  const count = Math.max(1, Math.ceil(total / pageSize));
  const current = Math.max(1, Math.min(count, Math.trunc(page)));
  const pages = [
    ...new Set(
      [1, count, current - 1, current, current + 1].filter(
        (n) => n >= 1 && n <= count,
      ),
    ),
  ].sort((a, b) => a - b);
  const slots: (number | string)[] = [];
  for (const n of pages) {
    const last = slots.at(-1);
    if (typeof last === 'number' && n - last > 1)
      slots.push(n - last === 2 ? last + 1 : `gap-${n}`);
    slots.push(n);
  }
  const change = (n: number) => {
    if (n >= 1 && n <= count && n !== current && total > 0) onPageChange(n);
  };
  return (
    <nav
      aria-label="Пагинация"
      {...props}
      className={`flex flex-wrap items-center justify-between gap-3.5 border-t border-line-soft bg-surface-row-hover px-5 py-3.5 ${className}`}
    >
      <p className="text-md text-fg-secondary">
        Показано{' '}
        <strong className="font-bold text-fg-strong">
          {total === 0 ? 0 : (current - 1) * pageSize + 1}–
          {Math.min(current * pageSize, total)}
        </strong>{' '}
        из <strong className="font-bold text-fg-strong">{total}</strong>
      </p>
      <div className="flex items-center gap-1">
        <button
          type="button"
          className="ds-page-button"
          aria-label="Предыдущая страница"
          disabled={total === 0 || current === 1}
          onClick={() => change(current - 1)}
        >
          <IconChevronLeft size={16} stroke={2} aria-hidden="true" />
        </button>
        {slots.map((slot) =>
          typeof slot === 'number' ? (
            <button
              type="button"
              key={slot}
              className="ds-page-button"
              disabled={total === 0}
              aria-label={`Страница ${slot}`}
              aria-current={slot === current ? 'page' : undefined}
              onClick={() => change(slot)}
            >
              {slot}
            </button>
          ) : (
            <span
              key={slot}
              aria-hidden="true"
              className="inline-flex size-8 items-center justify-center text-sm text-fg-tertiary"
            >
              …
            </span>
          ),
        )}
        <button
          type="button"
          className="ds-page-button"
          aria-label="Следующая страница"
          disabled={total === 0 || current === count}
          onClick={() => change(current + 1)}
        >
          <IconChevronRight size={16} stroke={2} aria-hidden="true" />
        </button>
      </div>
    </nav>
  );
}
