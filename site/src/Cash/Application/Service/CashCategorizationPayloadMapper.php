<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\DTO\CashCategorizationExample;
use App\Cash\Application\DTO\CashCategorizationOperation;

/**
 * Справочник и операции в форме контракта ai-task/v1 `categorize_payload`. Один код для
 * постановки задач и выгрузки золотого набора: evals проверяют ровно то, что видит модель в проде.
 */
final readonly class CashCategorizationPayloadMapper
{
    /** Лимиты контракта categorize_payload. */
    private const MAX_PATH = 500;
    private const MAX_DESCRIPTION = 500;
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
     * Примеры со статьёй вне справочника пачки (архивная, стала группой) отбрасываются: сервис отверг бы
     * весь вход. Пустой список не передаётся — вход остаётся прежней формы.
     *
     * @param list<CashCategorizationCategory> $categories
     * @param list<CashCategorizationOperation> $operations
     * @param list<CashCategorizationExample> $examples
     *
     * @return array{categories: list<array<string, string>>, operations: list<array<string, string|null>>, examples?: list<array<string, string|null>>}
     */
    public function payload(array $categories, array $operations, array $examples = []): array
    {
        $payload = [
            'categories' => array_map($this->category(...), $categories),
            'operations' => array_map($this->operation(...), $operations),
        ];
        $known = array_fill_keys(array_map(static fn (CashCategorizationCategory $c): string => $c->id, $categories), true);
        $examples = array_values(array_filter($examples, static fn (CashCategorizationExample $e): bool => isset($known[$e->categoryId])));
        if ([] !== $examples) {
            $payload['examples'] = array_map($this->example(...), $examples);
        }

        return $payload;
    }

    /**
     * @return array<string, string|null>
     */
    public function example(CashCategorizationExample $example): array
    {
        return [
            'direction' => 'INFLOW' === $example->direction ? 'inflow' : 'outflow',
            'description' => $example->description,
            'counterparty' => null === $example->counterparty ? null : mb_substr($example->counterparty, 0, self::MAX_COUNTERPARTY),
            'category_id' => $example->categoryId,
        ];
    }

    /**
     * Описание «что входит и почему» — только непустое: ai-service принимает его с контракта ai-task v1.2.0.
     *
     * @return array<string, string>
     */
    public function category(CashCategorizationCategory $category): array
    {
        $result = [
            'id' => $category->id,
            'path' => mb_substr($category->path, -self::MAX_PATH),
            'direction' => match ($category->operationType) {
                'INFLOW' => 'inflow',
                'OUTFLOW' => 'outflow',
                default => 'any',
            },
        ];

        $description = trim((string) $category->description);
        if ('' !== $description) {
            $result['description'] = mb_substr($description, 0, self::MAX_DESCRIPTION);
        }

        return $result;
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
