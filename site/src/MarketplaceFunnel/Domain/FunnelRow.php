<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Domain;

/**
 * Строка воронки товара за день: ступень, которой у маркетплейса нет, — `null`, а не ноль.
 */
final readonly class FunnelRow
{
    /** Версия правил перевода: смена — все дни переводятся заново. */
    public const RULES_VERSION = '1';

    /** Колонки `mfn_row` со ступенями, в порядке воронки. */
    public const COLUMNS = ['impressions', 'impressions_search', 'card_views', 'cart_adds', 'orders', 'revenue_minor', 'buyouts', 'cancels', 'returns'];

    /**
     * @param array<string, int|null> $values колонка → значение, все ключи COLUMNS
     */
    public function __construct(
        public string $sku,
        public array $values,
    ) {
    }

    /** Контрольная сумма строки для гейта «таблица = перевод». */
    public function controlSum(): int
    {
        return (int) array_sum($this->values);
    }

    /** Число из источника: целое либо целое, записанное дробным (`5.0`). */
    public static function count(mixed $value): int
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < 0 || (float) (int) $value !== (float) $value) {
            throw new \DomainException('funnel metric is not a non-negative whole number');
        }

        return (int) $value;
    }

    /** Сумма в рублях из источника → копейки. */
    public static function minor(mixed $value): int
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new \DomainException('funnel amount is not a number');
        }

        return (int) round($value * 100);
    }
}
