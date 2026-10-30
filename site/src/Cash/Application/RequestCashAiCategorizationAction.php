<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\CashAiCategorizationRequestResult;
use App\Cash\Application\DTO\CashCategorizationCategory;
use App\Cash\Application\DTO\CashCategorizationOperation;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
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
 * На пачку: строки `requested` с task_id пишутся одной транзакцией, задача ставится после
 * commit. Сбой между commit и постановкой не теряет операции навсегда: строка без ответа
 * дольше суток закрывается как `failed/lost`, и следующий прогон запрашивает операцию снова.
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
    private const LOST_AFTER_HOURS = 24;
    private const LOST_PER_RUN = 1000;
    private const AMOUNT = '/^\d{1,16}\.\d{2}$/D';

    public function __construct(
        private readonly CashCategorizationQuery $query,
        private readonly CashCategorySuggestionRepository $suggestions,
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
        $lost = $this->closeLost($companyId, $now);

        $categories = $this->query->findLeafCategories($companyId);
        if ([] === $categories) {
            return new CashAiCategorizationRequestResult(enabled: true, lost: $lost, skippedReason: 'no_categories');
        }
        if (\count($categories) > self::MAX_CATEGORIES) {
            $this->logger->warning('AI categorization skipped: category tree exceeds the contract limit.', [
                'companyId' => $companyId,
                'categories' => \count($categories),
            ]);

            return new CashAiCategorizationRequestResult(enabled: true, lost: $lost, skippedReason: 'too_many_categories');
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
            $this->entityManager->wrapInTransaction(function () use ($batch, $companyId, $taskId, $now): void {
                foreach ($batch as $operation) {
                    $this->entityManager->persist(new CashCategorySuggestion($companyId, $operation->id, $taskId, $now));
                }
            });
            $this->ai->requestTask($companyId, self::TASK_TYPE, $this->payload($categories, $batch), $key);
            $requested += \count($batch);
            ++$tasks;
        }

        $this->logger->info('AI categorization requested.', [
            'companyId' => $companyId,
            'operations' => $requested,
            'tasks' => $tasks,
            'lost' => $lost,
        ]);

        return new CashAiCategorizationRequestResult(enabled: true, lost: $lost, requested: $requested, tasks: $tasks);
    }

    private function closeLost(string $companyId, \DateTimeImmutable $now): int
    {
        $lost = $this->suggestions->findRequestedBefore(
            $companyId,
            $now->modify(sprintf('-%d hours', self::LOST_AFTER_HOURS)),
            self::LOST_PER_RUN,
        );
        if ([] === $lost) {
            return 0;
        }
        $this->entityManager->wrapInTransaction(static function () use ($lost, $now): void {
            foreach ($lost as $suggestion) {
                $suggestion->fail('lost', null, $now);
            }
        });
        $this->logger->warning('AI categorization requests without an answer were closed as lost.', [
            'companyId' => $companyId,
            'count' => \count($lost),
        ]);

        return \count($lost);
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
