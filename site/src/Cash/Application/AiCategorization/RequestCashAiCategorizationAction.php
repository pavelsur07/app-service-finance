<?php

declare(strict_types=1);

namespace App\Cash\Application\AiCategorization;

use App\Cash\Application\DTO\CashAiCategorizationRequestResult;
use App\Cash\Application\DTO\CashCategorizationOperation;
use App\Cash\Application\Service\AiCategorization\CashCategorizationPayloadMapper;
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
    /**
     * Текущий промпт сервиса (`PROMPT_VERSION` задачи в ai-service; сверка —
     * services/ai-service/tests/unit/test_symfony_prompt_version.py): «нет ответа» на прошлой версии
     * переспрашивается один раз.
     */
    public const PROMPT_VERSION = 'categorize_transactions/v4';
    /** Примеры разнесения компании на пачку (контракт — до 50): по три на операцию, за год. */
    private const EXAMPLES_PER_OPERATION = 3;
    private const EXAMPLES_PER_BATCH = 30;
    private const EXAMPLES_DAYS = 365;
    /** Лимиты контракта categorize_payload. */
    private const MAX_CATEGORIES = 1000;
    /** Наибольшее окно кандидатов (охват cron); окно компании — `lookbackDays` из лимитов ИИ. */
    public const LOOKBACK_DAYS = 90;
    /** Автоправила срабатывают через ~10 с после записи операции; запас на очередь. */
    private const SETTLE_MINUTES = 10;
    private const REDISPATCH_AFTER_HOURS = 1;
    private const REDISPATCH_PER_RUN = 100;
    private const EXPIRE_AFTER_DAYS = 7;
    private const HOUSEKEEPING_PER_RUN = 1000;

    public function __construct(
        private readonly CashCategorizationQuery $query,
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly CashCategorizationTaskRepository $tasks,
        private readonly CashCategorizationPayloadMapper $mapper,
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
        // Переотправка — те же задачи с тем же ключом: лимит её не останавливает, сервис не платит дважды.
        $redispatched = $this->redispatchWaiting($companyId, $now);

        // Расход приходит с импортом результатов (раз в 5 мин): перерасход — не больше одного запуска.
        $budget = $this->ai->budgetFor($companyId);
        if ($budget->exceeded()) {
            $this->logger->warning('AI categorization skipped: monthly AI budget is exhausted.', [
                'companyId' => $companyId,
                'spentRub' => $budget->spentRub,
                'limitRub' => $budget->limitRub,
                'totalSpentRub' => $budget->totalSpentRub,
                'totalLimitRub' => $budget->totalLimitRub,
            ]);

            return new CashAiCategorizationRequestResult(enabled: true, expired: $expired, redispatched: $redispatched, skippedReason: 'budget_exceeded');
        }
        // Пока прошлые запросы без ответа, их стоимость не в учёте: новые пачки ждут. Иначе при
        // вставшем импорте лимит видел бы прежний расход, а отправка шла бы каждый час. Истёкшие
        // без ответа за последние EXPIRE_AFTER_DAYS держат паузу ещё столько же (истечение — ERROR).
        // ponytail: пауза по времени, не до подтверждения импорта; точно — учётом отправленных задач в ServiceGateway.
        if ([] !== $this->suggestions->findRequestedBefore($companyId, $now, 1)
            || $this->suggestions->hasExpiredSince($companyId, $now->modify(sprintf('-%d days', self::EXPIRE_AFTER_DAYS)))) {
            return new CashAiCategorizationRequestResult(enabled: true, expired: $expired, redispatched: $redispatched, skippedReason: 'awaiting_results');
        }

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
                $now->modify(sprintf('-%d days', min($budget->lookbackDays, self::LOOKBACK_DAYS)))->setTime(0, 0),
                $now->modify(sprintf('-%d minutes', self::SETTLE_MINUTES)),
                $limit,
                self::PROMPT_VERSION,
            ),
            $this->mapper->isRepresentable(...),
        ));

        $requested = 0;
        $tasks = 0;
        foreach (array_chunk($operations, self::BATCH_SIZE) as $batch) {
            $key = 'cash-categorize:'.Uuid::uuid7()->toString();
            $taskId = $this->ai->taskIdFor($companyId, self::TASK_TYPE, $key);
            $examples = $this->query->findExamplesFor(
                $companyId,
                array_map(static fn (CashCategorizationOperation $o): string => $o->id, $batch),
                $now->modify(sprintf('-%d days', self::EXAMPLES_DAYS))->setTime(0, 0),
                self::EXAMPLES_PER_OPERATION,
                self::EXAMPLES_PER_BATCH,
            );
            $payload = $this->mapper->payload($categories, $batch, $examples);
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
            // Неделя без ответа — сломан сервис или импорт результатов; запросы могли быть оплачены.
            $this->logger->error('AI categorization requests without an answer expired.', [
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
}
