import type { ComponentPropsWithRef } from 'react';
const marketplaces = {
  ozon: ['Oz', 'Ozon'],
  wb: ['WB', 'Wildberries'],
  yamarket: ['ЯМ', 'Яндекс Маркет'],
  megamarket: ['ММ', 'Мегамаркет'],
} as const;
const colors = {
  ozon: 'bg-(--ds-mp-ozon) text-(--ds-mp-ozon-fg)',
  wb: 'bg-(--ds-mp-wb) text-(--ds-mp-wb-fg)',
  yamarket: 'bg-(--ds-mp-yamarket) text-(--ds-mp-yamarket-fg)',
  megamarket: 'bg-(--ds-mp-megamarket) text-fg-strong',
};
export interface MarketplaceMarkProps extends ComponentPropsWithRef<'span'> {
  marketplace: keyof typeof marketplaces;
  inline?: boolean;
}
export function MarketplaceMark({
  marketplace,
  inline = false,
  className = '',
  ...props
}: MarketplaceMarkProps) {
  const [initials, label] = marketplaces[marketplace];
  const mark = (
    <span
      aria-hidden="true"
      className={`inline-flex shrink-0 items-center justify-center font-extrabold tracking-[.02em] ${inline ? 'size-4 rounded-xs text-[8px]' : 'size-7 rounded-md text-xs'} ${colors[marketplace]}`}
    >
      {initials}
    </span>
  );
  return (
    <span
      role="img"
      aria-label={label}
      {...props}
      className={`inline-flex shrink-0 items-center ${inline ? 'gap-1.5 rounded-sm border border-line bg-surface-card py-1 pr-2 pl-1 text-sm font-semibold text-fg-strong' : ''} ${className}`}
    >
      {mark}
      {inline && <span aria-hidden="true">{label}</span>}
    </span>
  );
}
