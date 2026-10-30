<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\DTO\CashCategorizationOperation;

/**
 * Справочник и операции в форме контракта ai-task/v1 `categorize_payload`. Один код для
 * постановки задач и выгрузки золотого набора: evals проверяют ровно то, что видит модель в проде.
 */
final readonly class CashCategorizationPayloadMapper
{
    /** Лимиты контракта categorize_payload. */
    private const MAX_PATH = 500;
    private const MAX_COUNTERPARTY = 255;
    private const AMOUNT = '/^\d{1,16}\.\d{2}$/D';

    /**
     * Сумма, которую принимает контракт: отрицательных и сверхдлинных сумм в учёте быть не должно.
     */
    public function isRepresentable(CashCategorizationOperation $operation): bool
    {
        return 1 === preg_match(self::AMOUNT, $operation->amount);
    }

    /**
     * @param list<CashCategorizationCategory> $categories
     * @param list<CashCategorizationOperation> $operations
     *
     * @return array{categories: list<array<string, string>>, operations: list<array<string, string|null>>}
     */
    public function payload(array $categories, array $operations): array
    {
        return [
            'categories' => array_map($this->category(...), $categories),
            'operations' => array_map($this->operation(...), $operations),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function category(CashCategorizationCategory $category): array
    {
        return [
            'id' => $category->id,
            'path' => mb_substr($category->path, -self::MAX_PATH),
            'direction' => match ($category->operationType) {
                'INFLOW' => 'inflow',
                'OUTFLOW' => 'outflow',
                default => 'any',
            },
        ];
    }

    /**
     * @return array<string, string|null>
     */
    public function operation(CashCategorizationOperation $operation): array
    {
        return [
            'id' => $operation->id,
            'direction' => 'INFLOW' === $operation->direction ? 'inflow' : 'outflow',
            'amount' => $operation->amount,
            'currency' => $operation->currency,
            'date' => $operation->occurredAt->format('Y-m-d'),
            'description' => $operation->description,
            'counterparty' => null === $operation->counterparty ? null : mb_substr($operation->counterparty, 0, self::MAX_COUNTERPARTY),
        ];
    }
}
