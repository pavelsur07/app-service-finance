import type { ComponentPropsWithRef } from 'react';
type Grade = 'A' | 'B' | 'C';
const categories = {
  AA: ['star', 'Звезда'],
  AB: ['star', 'Сильный'],
  BA: ['star', 'Сильный'],
  BB: ['mid', 'Стабильный середняк'],
  AC: ['attention', 'Внимание'],
  CA: ['attention', 'Внимание'],
  BC: ['weak', 'Посредственный'],
  CB: ['weak', 'Посредственный'],
  CC: ['tail', 'Хвост'],
} as const;
const colors = {
  star: 'bg-(--ds-abc-star-bg) text-(--ds-abc-star-text)',
  mid: 'bg-(--ds-abc-mid-bg) text-(--ds-abc-mid-text)',
  attention: 'bg-(--ds-abc-attention-bg) text-(--ds-abc-attention-text)',
  weak: 'bg-(--ds-abc-weak-bg) text-(--ds-abc-weak-text)',
  tail: 'bg-(--ds-abc-tail-bg) text-(--ds-abc-tail-text)',
};
export interface AbcPillProps extends ComponentPropsWithRef<'span'> {
  revenue: Grade;
  profit: Grade;
}
export function AbcPill({
  revenue,
  profit,
  className = '',
  ...props
}: AbcPillProps) {
  const pair = `${revenue}${profit}` as keyof typeof categories;
  const [tone, description] = categories[pair];
  return (
    <span
      title={description}
      aria-label={`Выручка ${revenue}, прибыль ${profit}: ${description}`}
      {...props}
      className={`inline-flex min-w-8.5 justify-center rounded-sm px-2 py-1 font-mono text-sm font-extrabold tracking-[.04em] ${colors[tone]} ${className}`}
    >
      {pair}
    </span>
  );
}
