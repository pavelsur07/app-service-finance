<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Индекс начислений by-day компании: по дате, SKU и модулю суммы — какие
 * `type_id` это могли быть. Связывает строку отчёта кабинета (где `type_id` нет)
 * с типом начисления. Собирается `OzonAccrualByDayTypeLookupQuery` уже
 * агрегированным в SQL, поэтому на ключ — список из одного-двух `type_id`.
 */
final class OzonAccrualTypeIndex
{
    /**
     * Компонент без `type_id` (продажа, комиссия) на том же ключе.
     */
    public const UNTYPED = 0;

    /**
     * @var array<string, list<int>>
     */
    private array $bySku = [];

    /**
     * @var array<string, list<int>>
     */
    private array $byAmount = [];

    /**
     * @param list<int> $typeIds
     */
    public function addSku(string $date, string $sku, int $amountMinor, array $typeIds): void
    {
        $this->bySku[self::key($date, $sku, $amountMinor)] = self::merge($this->bySku[self::key($date, $sku, $amountMinor)] ?? [], $typeIds);
    }

    /**
     * @param list<int> $typeIds
     */
    public function addAmount(string $date, int $amountMinor, array $typeIds): void
    {
        $this->byAmount[self::key($date, '', $amountMinor)] = self::merge($this->byAmount[self::key($date, '', $amountMinor)] ?? [], $typeIds);
    }

    /**
     * @return list<int>
     */
    public function typeIds(string $date, ?string $sku, int $amountMinor): array
    {
        $amountMinor = abs($amountMinor);

        return null !== $sku && '' !== $sku
            ? ($this->bySku[self::key($date, $sku, $amountMinor)] ?? [])
            : ($this->byAmount[self::key($date, '', $amountMinor)] ?? []);
    }

    /**
     * Единственный `type_id` ключа или null: ключа нет, кандидатов несколько или
     * на ключе есть компонент без `type_id`.
     */
    public function uniqueTypeId(string $date, ?string $sku, int $amountMinor): ?int
    {
        $candidates = $this->typeIds($date, $sku, $amountMinor);

        return 1 === \count($candidates) && self::UNTYPED !== $candidates[0] ? $candidates[0] : null;
    }

    private static function key(string $date, string $sku, int $amountMinor): string
    {
        return $date.'|'.$sku.'|'.abs($amountMinor);
    }

    /**
     * @param list<int> $left
     * @param list<int> $right
     *
     * @return list<int>
     */
    private static function merge(array $left, array $right): array
    {
        $merged = array_values(array_unique([...$left, ...$right]));
        sort($merged);

        return $merged;
    }
}
