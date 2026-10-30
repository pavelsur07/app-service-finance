<?php

declare(strict_types=1);

namespace App\Cash\Application\Service;

use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\RequestCashAiCategorizationAction;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Результат categorize_transactions → подсказки статей (режим подсказок, без автоприменения).
 *
 * Вызывается импортом ServiceGateway внутри его транзакции: только изменение сущностей.
 * Идемпотентен: обрабатываются только строки задачи в статусе `requested`.
 * Подсказка устаревает, если операция уже получила статью или статья больше не конечная
 * активная статья компании: сервис проверял статью по справочнику на момент постановки.
 */
final readonly class CashCategorizationResultConsumer implements AiTaskResultConsumerInterface
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(
        private CashCategorySuggestionRepository $suggestions,
        private CashCategorizationQuery $query,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(string $taskType): bool
    {
        return RequestCashAiCategorizationAction::TASK_TYPE === $taskType;
    }

    public function consume(AiTaskResult $result): void
    {
        $rows = $this->suggestions->findRequestedByTask($result->companyId, $result->taskId);
        if ([] === $rows) {
            return;
        }
        $now = $this->clock->now();

        if (\in_array($result->status, [AiResultStatus::FAILED, AiResultStatus::REJECTED], true) || null === $result->output) {
            $this->failAll($rows, $result->errorCode ?? $result->status->value, $result->status->value, $now);

            return;
        }

        $items = $this->items($result->output);
        if (null === $items) {
            // Сервис проверяет выход по схеме; сюда попадает только рассинхрон контракта.
            $this->logger->error('AI categorization output violates the contract.', [
                'companyId' => $result->companyId,
                'taskId' => $result->taskId,
            ]);
            $this->failAll($rows, 'contract_violation', $result->status->value, $now);

            return;
        }

        $eligible = array_fill_keys(array_map(
            static fn (CashCategorizationCategory $c): string => $c->id,
            $this->query->findLeafCategories($result->companyId),
        ), true);
        $uncategorized = $this->query->uncategorizedAmong(
            $result->companyId,
            array_map(static fn (CashCategorySuggestion $s): string => $s->getTransactionId(), $rows),
        );
        $model = [] === $result->models ? null : $result->models[array_key_last($result->models)];

        foreach ($rows as $row) {
            $item = $items[$row->getTransactionId()] ?? null;
            if (null === $item) {
                $row->fail('missing_item', $result->status->value, $now);
            } elseif (!isset($uncategorized[$row->getTransactionId()])) {
                $row->markStale('categorized', $now);
            } elseif (null !== $item['categoryId'] && !isset($eligible[$item['categoryId']])) {
                $row->markStale('category_unavailable', $now);
            } else {
                $row->suggest($item['categoryId'], $item['confidence'], $result->status->value, $model, $result->promptVersion, $now);
            }
        }
    }

    /**
     * @param list<CashCategorySuggestion> $rows
     */
    private function failAll(array $rows, string $errorCode, string $verdict, \DateTimeImmutable $now): void
    {
        foreach ($rows as $row) {
            $row->fail($errorCode, $verdict, $now);
        }
    }

    /**
     * Разбор `categorize_output`: operation_id → статья и уверенность; null — нарушение контракта.
     *
     * @param array<string, mixed> $output
     *
     * @return array<string, array{categoryId: ?string, confidence: float}>|null
     */
    private function items(array $output): ?array
    {
        $items = $output['items'] ?? null;
        if (!\is_array($items) || !array_is_list($items)) {
            return null;
        }
        $parsed = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                return null;
            }
            $operationId = $item['operation_id'] ?? null;
            $categoryId = $item['category_id'] ?? null;
            $confidence = $item['confidence'] ?? null;
            if (!\is_string($operationId)
                || (null !== $categoryId && (!\is_string($categoryId) || 1 !== preg_match(self::UUID, $categoryId)))
                || !(\is_float($confidence) || \is_int($confidence))
                || $confidence < 0 || $confidence > 1) {
                return null;
            }
            $parsed[$operationId] = ['categoryId' => $categoryId, 'confidence' => (float) $confidence];
        }

        return $parsed;
    }
}
