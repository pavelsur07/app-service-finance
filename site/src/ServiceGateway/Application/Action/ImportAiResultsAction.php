<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\Action;

use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Application\DTO\ImportAiResultsResult;
use App\ServiceGateway\Exception\ContractViolationException;
use App\ServiceGateway\Facade\AiTaskResultConsumerInterface;
use App\ServiceGateway\Infrastructure\Client\AiServiceClientInterface;
use App\ServiceGateway\Infrastructure\Client\AiTaskV1Parser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Забор готовых результатов ai-service (pull + ack, services/AGENTS.md §3).
 *
 * Прогон проходит всю очередь неподтверждённых результатов от начала: результат,
 * пропущенный в прошлый раз (нет потребителя, сбой потребителя, нарушение контракта),
 * остаётся в сервисе и не загораживает следующие.
 *
 * По результату: HTTP вне транзакции → потребитель в своей транзакции → ack страницы
 * после commit. Сбой ack — сервис отдаст результаты снова, потребители идемпотентны
 * по taskId. Сбой одного потребителя не останавливает импорт: результат остаётся
 * неподтверждённым, EntityManager пересоздаётся.
 *
 * Принятый результат пишет строку `ai_usage` (токены, стоимость) в транзакции потребителя:
 * сбой потребителя откатывает и учёт, повторный импорт того же task_id строку не задваивает.
 */
final readonly class ImportAiResultsAction
{
    public const PAGE_SIZE = 100;
    private const LOGGED_TASK_IDS = 20;

    /**
     * @param iterable<AiTaskResultConsumerInterface> $consumers
     * @param array<string, array{input: float|null, output: float|null}> $prices ₽ за 1 млн токенов
     */
    public function __construct(
        private AiServiceClientInterface $client,
        #[AutowireIterator(AiTaskResultConsumerInterface::TAG)]
        private iterable $consumers,
        private ManagerRegistry $managerRegistry,
        private LoggerInterface $logger,
        #[Autowire('%ai.prices%')]
        private array $prices = [],
    ) {
    }

    public function __invoke(): ImportAiResultsResult
    {
        $received = 0;
        $acked = 0;
        /** @var array<string, int> $unhandledByType */
        $unhandledByType = [];
        /** @var list<string> $failedTaskIds */
        $failedTaskIds = [];
        $failedClasses = [];
        /** @var list<string> $invalid */
        $invalid = [];
        /** @var array<string, true> $unpricedModels */
        $unpricedModels = [];
        $after = null;

        while (true) {
            $page = $this->client->fetchResults($after, self::PAGE_SIZE);
            $received += \count($page->items) + \count($page->invalid);
            array_push($invalid, ...$page->invalid);

            $handled = [];
            foreach ($page->items as $result) {
                $consumer = $this->consumerFor($result);
                if (null === $consumer) {
                    $unhandledByType[$result->taskType] = ($unhandledByType[$result->taskType] ?? 0) + 1;
                    continue;
                }
                $failure = $this->consume($consumer, $result);
                if (null === $failure) {
                    $handled[] = $result->taskId;
                    if (null === $this->cost($result)) {
                        foreach ($result->models as $model) {
                            if (null === ($this->prices[$model]['input'] ?? null) || null === ($this->prices[$model]['output'] ?? null)) {
                                $unpricedModels[$model] = true;
                            }
                        }
                    }
                } else {
                    $failedTaskIds[] = $result->taskId;
                    $failedClasses[$failure] = true;
                }
            }
            if ([] !== $handled) {
                $acked += $this->client->ack($handled);
            }

            $next = $page->nextCursor;
            if (null === $next) {
                break;
            }
            // Курсор — растущий seq сервиса; без роста цикл не закончился бы.
            if (null !== $after && (int) $next <= (int) $after) {
                throw ContractViolationException::at(AiTaskV1Parser::CONTRACT, '$.next_cursor', 'must grow between pages');
            }
            $after = $next;
        }

        $this->logLeftovers($unhandledByType, $failedTaskIds, array_keys($failedClasses), $invalid);
        if ([] !== $unpricedModels) {
            $this->logger->warning('AI usage recorded without cost: model price is unknown (ai.prices).', [
                'models' => array_keys($unpricedModels),
            ]);
        }

        return new ImportAiResultsResult(
            received: $received,
            acked: $acked,
            unhandled: array_sum($unhandledByType),
            failed: \count($failedTaskIds),
            invalid: \count($invalid),
        );
    }

    /**
     * @return string|null класс исключения потребителя; null — результат принят
     */
    private function consume(AiTaskResultConsumerInterface $consumer, AiTaskResult $result): ?string
    {
        $em = $this->entityManager();
        try {
            $em->wrapInTransaction(function () use ($consumer, $result, $em): void {
                $consumer->consume($result);
                $this->recordUsage($em, $result);
            });

            return null;
        } catch (\Throwable $e) {
            // Технический catch: изоляция результата. Ошибка уходит в агрегированный error прогона.
            if (!$em->isOpen()) {
                $this->managerRegistry->resetManager();
            }

            return $e::class;
        }
    }

    private function recordUsage(EntityManagerInterface $em, AiTaskResult $result): void
    {
        $em->getConnection()->executeStatement(
            'INSERT INTO ai_usage (id, task_id, company_id, task_type, status, model, input_tokens, output_tokens, cost_rub, completed_at)
             VALUES (:id, :task, :company, :type, :status, :model, :in, :out, :cost, :completed)
             ON CONFLICT (task_id) DO NOTHING',
            [
                'id' => Uuid::uuid7()->toString(),
                'task' => $result->taskId,
                'company' => strtolower($result->companyId),
                'type' => $result->taskType,
                'status' => $result->status->value,
                'model' => $result->models[0] ?? null,
                'in' => $result->inputTokens,
                'out' => $result->outputTokens,
                'cost' => $this->cost($result),
                // Время в БД — МСК без зоны, как остальные timestamp прода.
                'completed' => $result->completedAt->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Стоимость в ₽; null — цена хотя бы одной модели неизвестна. Контракт отдаёт токены суммой
     * по всем вызовам (повтор, запасная модель), поэтому цена — наибольшая среди моделей ответа:
     * оценка сверху, лимит не занижается.
     * ponytail: оценка сверху; точно — когда контракт начнёт отдавать usage по моделям.
     */
    private function cost(AiTaskResult $result): ?string
    {
        if (0 === $result->inputTokens + $result->outputTokens) {
            return '0.0000';
        }
        $input = $output = null;
        foreach ($result->models as $model) {
            $price = $this->prices[$model] ?? null;
            if (null === $price || null === $price['input'] || null === $price['output']) {
                return null;
            }
            $input = max($input ?? 0.0, $price['input']);
            $output = max($output ?? 0.0, $price['output']);
        }
        if (null === $input || null === $output) {
            return null;
        }

        return number_format(($result->inputTokens * $input + $result->outputTokens * $output) / 1_000_000, 4, '.', '');
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->managerRegistry->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    /**
     * Один агрегированный error за прогон: результаты остались в сервисе и требуют человека.
     *
     * @param array<string, int> $unhandledByType
     * @param list<string> $failedTaskIds
     * @param list<string> $failedClasses
     * @param list<string> $invalid
     */
    private function logLeftovers(array $unhandledByType, array $failedTaskIds, array $failedClasses, array $invalid): void
    {
        if ([] !== $unhandledByType) {
            $this->logger->error('AI results without a consumer were left unacknowledged.', [
                'count' => array_sum($unhandledByType),
                'taskTypes' => array_keys($unhandledByType),
            ]);
        }
        if ([] !== $failedTaskIds) {
            $this->logger->error('AI result consumers failed; results were left unacknowledged.', [
                'count' => \count($failedTaskIds),
                'taskIds' => \array_slice($failedTaskIds, 0, self::LOGGED_TASK_IDS),
                'exceptionClasses' => $failedClasses,
            ]);
        }
        if ([] !== $invalid) {
            $this->logger->error('AI results violating the contract were left unacknowledged.', [
                'count' => \count($invalid),
                'violations' => \array_slice($invalid, 0, self::LOGGED_TASK_IDS),
            ]);
        }
    }

    private function consumerFor(AiTaskResult $result): ?AiTaskResultConsumerInterface
    {
        foreach ($this->consumers as $consumer) {
            if ($consumer->supports($result->taskType)) {
                return $consumer;
            }
        }

        return null;
    }
}
