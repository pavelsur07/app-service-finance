import type { ComponentPropsWithRef, ReactNode } from 'react';
import {
  IconArrowsSort,
  IconChevronUp,
  IconChevronDown,
} from '@tabler/icons-react';
export interface TableProps extends ComponentPropsWithRef<'table'> {
  caption: ReactNode;
}
export function Table({
  caption,
  className = '',
  children,
  ...props
}: TableProps) {
  return (
    <div className="max-w-full overflow-x-auto">
      <table
        {...props}
        className={`ds-table w-full border-separate border-spacing-0 tabular-nums ${className}`}
      >
        <caption className="sr-only">{caption}</caption>
        {children}
      </table>
    </div>
  );
}
export interface SortHeaderProps extends ComponentPropsWithRef<'th'> {
  direction?: 'ascending' | 'descending' | null;
  onSort: () => void;
  align?: 'left' | 'right';
}
export function SortHeader({
  direction,
  onSort,
  align = 'left',
  className = '',
  children,
  ...props
}: SortHeaderProps) {
  const Icon =
    direction === 'ascending'
      ? IconChevronUp
      : direction === 'descending'
        ? IconChevronDown
        : IconArrowsSort;
  return (
    <th
      scope="col"
      {...props}
      aria-sort={direction ?? 'none'}
      data-align={align}
      className={`ds-sort-header ${className}`}
    >
      <button
        type="button"
        onClick={onSort}
        className={`inline-flex w-full items-center gap-1.5 rounded-xs text-inherit ${align === 'right' ? 'justify-end' : ''}`}
      >
        {children}
        <Icon size={16} stroke={2} aria-hidden="true" />
        <span className="sr-only">: сортировать</span>
      </button>
    </th>
  );
}
export interface TotalsRowProps extends ComponentPropsWithRef<'tr'> {
  children?: ReactNode;
}
/** The caller supplies totals; DS never calculates financial values. */
export function TotalsRow({ className = '', ...props }: TotalsRowProps) {
  return <tr {...props} className={`ds-table-totals ${className}`} />;
}
