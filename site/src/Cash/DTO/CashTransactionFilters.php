<?php

declare(strict_types=1);

namespace App\Cash\DTO;

use App\Cash\Enum\FiatCurrency;

/**
 * Разбор фильтров списка операций ДДС из query string.
 *
 * Живёт в одном месте, чтобы экран и экспорт не разъехались при добавлении нового фильтра.
 */
final readonly class CashTransactionFilters
{
    private const KEYS = [
        'dateFrom',
        'dateTo',
        'accountId',
        'categoryId',
        'counterpartyId',
        'direction',
        'currency',
        'amountMin',
        'amountMax',
        'q',
    ];

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, string|null>
     */
    public static function fromQuery(array $query): array
    {
        $filters = [];
        foreach (self::KEYS as $key) {
            $value = $query[$key] ?? null;
            $filters[$key] = is_string($value) && '' !== $value ? $value : null;
        }
        if (null !== $filters['currency']) {
            $filters['currency'] = FiatCurrency::fromCode($filters['currency'])->value;
        }

        return $filters;
    }
}
