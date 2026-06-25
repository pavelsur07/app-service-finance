export function formatAmount(value: number | string | null | undefined): string {
  const numericValue = Number(value ?? 0);

  return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(numericValue)} ₽`;
}
