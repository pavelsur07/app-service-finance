<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\FiatCurrency;
use Ramsey\Uuid\Uuid;

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
        // Переход из отчёта ДДС: статья вместе с поддеревом, проекты и ЦФО списком UUID через запятую.
        // Списки строкой, а не `[]`: на скалярных значениях держатся экспорт, чипы и пагинация.
        'includeChildren',
        'projectIds',
        'centerIds',
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
        if ('1' !== $filters['includeChildren']) {
            $filters['includeChildren'] = null;
        }
        foreach (['projectIds', 'centerIds'] as $key) {
            if (null !== $filters[$key]) {
                $filters[$key] = implode(',', self::uuidList($filters[$key]));
            }
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    public static function uuidList(string $csv): array
    {
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), static fn (string $id): bool => '' !== $id)));
        foreach ($ids as $id) {
            if (!Uuid::isValid($id)) {
                throw new \InvalidArgumentException('Некорректный фильтр перехода из отчёта.');
            }
        }

        return $ids;
    }
}
