export function formatAmount(value: number | string | null | undefined, currency = 'RUB'): string {
  const numericValue = Number(value ?? 0);

  return new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(numericValue);
}
