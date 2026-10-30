<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CashAiCategorizationRequestResult;
use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\DTO\CashCategorizationOperation;
use App\Cash\Entity\Transaction\CashCategorizationTask;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashCategorizationTaskRepository;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\ClockInterface;
use Webmozart\Assert\Assert;

/**
 * Постановка ИИ-подсказок статей ДДС для операций без статьи (docs/tasks/ai/stages/stage-2.md).
 *
 * На пачку: запись задачи (ключ повтора и payload) и строки `requested` с её task_id пишутся
 * одной транзакцией, задача ставится после commit. Задача без ответа дольше часа после
 * последней отправки переотправляется с тем же ключом и тем же payload (сбой между commit и
 * отправкой, сообщение ушло в failed, сервис был недоступен) — запоздалый ответ первой
 * отправки тоже принимается. Строки без ответа дольше 7 дней закрываются как
 * `failed/expired`; транспортные сбои не считаются неудачной попыткой операции.
 * Параллельный прогон по той же компании отсекает частичный unique открытой подсказки
 * (команда держит lock).
 */
final class RequestCashAiCategorizationAction
{
    public const TASK_TYPE = 'categorize_transactions';
    public const BATCH_SIZE = 50;
    /** Лимиты контракта categorize_payload. */
    private const MAX_CATEGORIES = 1000;
    private const MAX_PATH = 500;
    private const MAX_COUNTERPARTY = 255;
    private const LOOKBACK_DAYS = 90;
    /** Автоправила срабатывают через ~10 с после записи операции; запас на очередь. */
    private const SETTLE_MINUTES = 10;
    private const REDISPATCH_AFTER_HOURS = 1;
    private const REDISPATCH_PER_RUN = 100;
    private const EXPIRE_AFTER_DAYS = 7;
    private const HOUSEKEEPING_PER_RUN = 1000;
    private const AMOUNT = '/^\d{1,16}\.\d{2}$/D';

    public function __construct(
        private readonly CashCategorizationQuery $query,
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly CashCategorizationTaskRepository $tasks,
        private readonly AiTaskFacadeInterface $ai,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, int $limit): CashAiCategorizationRequestResult
    {
        Assert::uuid($companyId);
        Assert::range($limit, 1, 1000);
        if (!$this->ai->isEnabledForCompany($companyId)) {
            return new CashAiCategorizationRequestResult(enabled: false);
        }

        $now = $this->clock->now();
        $expired = $this->expireAndCleanUp($companyId, $now);
        $redispatched = $this->redispatchWaiting($companyId, $now);

        $categories = $this->query->findLeafCategories($companyId);
        if ([] === $categories) {
            return new CashAiCategorizationRequestResult(enabled: true, expired: $expired, redispatched: $redispatched, skippedReason: 'no_categories');
        }
        if (\count($categories) > self::MAX_CATEGORIES) {
            $this->logger->warning('AI categorization skipped: category tree exceeds the contract limit.', [
                'companyId' => $companyId,
                'categories' => \count($categories),
            ]);

            return new CashAiCategorizationRequestResult(enabled: true, expired: $expired, redispatched: $redispatched, skippedReason: 'too_many_categories');
        }

        $operations = array_values(array_filter(
            $this->query->findCandidates(
                $companyId,
                $now->modify(sprintf('-%d days', self::LOOKBACK_DAYS))->setTime(0, 0),
                $now->modify(sprintf('-%d minutes', self::SETTLE_MINUTES)),
                $limit,
            ),
            // Отрицательные и сверхдлинные суммы контракт не принимает; таких операций в учёте быть не должно.
            static fn (CashCategorizationOperation $op): bool => 1 === preg_match(self::AMOUNT, $op->amount),
        ));

        $requested = 0;
        $tasks = 0;
        foreach (array_chunk($operations, self::BATCH_SIZE) as $batch) {
            $key = 'cash-categorize:'.Uuid::uuid7()->toString();
            $taskId = $this->ai->taskIdFor($companyId, self::TASK_TYPE, $key);
            $payload = $this->payload($categories, $batch);
            $this->entityManager->wrapInTransaction(function () use ($batch, $companyId, $taskId, $key, $payload, $now): void {
                $this->entityManager->persist(new CashCategorizationTask($taskId, $companyId, $key, $payload, $now));
                foreach ($batch as $operation) {
                    $this->entityManager->persist(new CashCategorySuggestion($companyId, $operation->id, $taskId, $now));
                }
            });
            $this->ai->requestTask($companyId, self::TASK_TYPE, $payload, $key);
            $requested += \count($batch);
            ++$tasks;
        }

        $this->logger->info('AI categorization requested.', [
            'companyId' => $companyId,
            'operations' => $requested,
            'tasks' => $tasks,
            'redispatched' => $redispatched,
            'expired' => $expired,
        ]);

        return new CashAiCategorizationRequestResult(
            enabled: true,
            expired: $expired,
            redispatched: $redispatched,
            requested: $requested,
            tasks: $tasks,
        );
    }

    /**
     * Строки без ответа дольше EXPIRE_AFTER_DAYS — `failed/expired`; записи задач без строк
     * `requested` больше не нужны для переотправки и удаляются.
     */
    private function expireAndCleanUp(string $companyId, \DateTimeImmutable $now): int
    {
        $expired = $this->suggestions->findRequestedBefore(
            $companyId,
            $now->modify(sprintf('-%d days', self::EXPIRE_AFTER_DAYS)),
            self::HOUSEKEEPING_PER_RUN,
        );
        if ([] !== $expired) {
            $this->entityManager->wrapInTransaction(static function () use ($expired, $now): void {
                foreach ($expired as $suggestion) {
                    $suggestion->fail('expired', null, $now);
                }
            });
            $this->logger->warning('AI categorization requests without an answer expired.', [
                'companyId' => $companyId,
                'count' => \count($expired),
            ]);
        }

        $settled = $this->tasks->findSettled($companyId, self::HOUSEKEEPING_PER_RUN);
        if ([] !== $settled) {
            $this->entityManager->wrapInTransaction(function () use ($settled): void {
                foreach ($settled as $task) {
                    $this->entityManager->remove($task);
                }
            });
        }

        return \count($expired);
    }

    /**
     * Переотправка задач без ответа с исходным ключом и payload. Отметка отправки
     * коммитится до отправки: падение между ними даст ещё одну переотправку через час.
     */
    private function redispatchWaiting(string $companyId, \DateTimeImmutable $now): int
    {
        $waiting = $this->tasks->findWaitingDispatchedBefore(
            $companyId,
            $now->modify(sprintf('-%d hours', self::REDISPATCH_AFTER_HOURS)),
            self::REDISPATCH_PER_RUN,
        );
        if ([] === $waiting) {
            return 0;
        }
        $this->entityManager->wrapInTransaction(static function () use ($waiting, $now): void {
            foreach ($waiting as $task) {
                $task->markDispatched($now);
            }
        });
        foreach ($waiting as $task) {
            $this->ai->requestTask($companyId, self::TASK_TYPE, $task->getPayload(), $task->getRequestKey());
        }
        $this->logger->warning('AI categorization tasks without an answer were dispatched again.', [
            'companyId' => $companyId,
            'count' => \count($waiting),
        ]);

        return \count($waiting);
    }

    /**
     * @param list<CashCategorizationCategory> $categories
     * @param list<CashCategorizationOperation> $operations
     *
     * @return array<string, mixed> payload по контракту categorize_payload
     */
    private function payload(array $categories, array $operations): array
    {
        return [
            'categories' => array_map(static fn (CashCategorizationCategory $c): array => [
                'id' => $c->id,
                'path' => mb_substr($c->path, -self::MAX_PATH),
                'direction' => match ($c->operationType) {
                    'INFLOW' => 'inflow',
                    'OUTFLOW' => 'outflow',
                    default => 'any',
                },
            ], $categories),
            'operations' => array_map(static fn (CashCategorizationOperation $o): array => [
                'id' => $o->id,
                'direction' => 'INFLOW' === $o->direction ? 'inflow' : 'outflow',
                'amount' => $o->amount,
                'currency' => $o->currency,
                'date' => $o->occurredAt->format('Y-m-d'),
                'description' => $o->description,
                'counterparty' => null === $o->counterparty ? null : mb_substr($o->counterparty, 0, self::MAX_COUNTERPARTY),
            ], $operations),
        ];
    }
}
