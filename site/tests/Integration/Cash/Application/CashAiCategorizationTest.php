<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AcceptCashCategorySuggestionAction;
use App\Cash\Application\DismissCashCategorySuggestionAction;
use App\Cash\Application\RequestCashAiCategorizationAction;
use App\Cash\Application\Service\CashCategorizationResultConsumer;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashCategorySuggestionDecisionOutcome;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Exception\CashCategorySuggestionStateException;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashCategorizationTaskRepository;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * ИИ-подсказки статей ДДС: постановка → ответ сервиса → принять / отклонить (этап 2.1).
 */
final class CashAiCategorizationTest extends IntegrationTestCase
{
    private Company $company;
    private User $user;
    private MoneyAccount $account;
    private CashflowCategory $rent;
    private CashflowCategory $revenue;
    private CashflowCategory $unallocated;
    private RecordingAiTaskFacade $ai;
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = UserBuilder::aUser()->withEmail('cash-ai@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($this->user)->withName('AI')->build();
        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main', 'RUB');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));

        $expenses = $this->category('Расходы', null);
        $this->rent = $this->category('Аренда', PaymentPlanType::OUTFLOW, $expenses);
        $this->revenue = $this->category('Выручка', PaymentPlanType::INFLOW);
        $this->unallocated = $this->category('Нераспределённое', null);
        $this->unallocated->markAsSystem(CashflowCategory::CODE_UNALLOCATED);

        foreach ([$this->user, $this->company, $this->account, $expenses, $this->rent, $this->revenue, $this->unallocated] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $this->ai = new RecordingAiTaskFacade();
        // Операции созданы «сейчас»: часы прогона — на час позже, автоправила успели отработать.
        $this->clock = new MockClock(new \DateTimeImmutable('+1 hour'));
    }

    public function testRequestsOnlyUncategorizedSettledOperationsAndIsRepeatSafe(): void
    {
        $plain = $this->transaction('Оплата аренды офиса');
        $unalloc = $this->transaction('Арендная плата', $this->unallocated);
        $this->transaction('Аренда', $this->rent);
        $this->transaction('Перевод между счетами', null, transfer: true);
        $this->transaction('Удалённая')->markDeleted(null);
        $this->transaction('Давняя', null, new \DateTimeImmutable('-200 days'));
        $this->em->flush();

        $result = $this->request();

        self::assertSame(2, $result->requested);
        self::assertSame(1, $result->tasks);
        self::assertCount(1, $this->ai->requests);
        $payload = $this->ai->requests[0]['payload'];
        $ids = array_column($payload['operations'], 'id');
        sort($ids);
        $expected = [$plain->getId(), $unalloc->getId()];
        sort($expected);
        self::assertSame($expected, $ids);
        // Только конечные несистемные статьи, с путём и направлением.
        $paths = array_column($payload['categories'], 'direction', 'path');
        self::assertSame(['Выручка' => 'inflow', 'Расходы / Аренда' => 'outflow'], $paths);
        self::assertSame('outflow', $payload['operations'][0]['direction']);
        self::assertMatchesRegularExpression('/^\d+\.\d{2}$/', $payload['operations'][0]['amount']);

        // Повтор: открытые подсказки не запрашиваются снова.
        $repeat = $this->request();
        self::assertSame(0, $repeat->requested);
        self::assertSame(2, $this->countSuggestions());
    }

    public function testDisabledServiceRequestsNothing(): void
    {
        $this->transaction('Оплата аренды');
        $this->em->flush();
        $this->ai->enabled = false;

        self::assertFalse($this->request()->enabled);
        self::assertSame(0, $this->countSuggestions());
    }

    public function testAnswerBecomesSuggestionAndRepeatedResultIsIgnored(): void
    {
        $rentTx = $this->transaction('Оплата аренды офиса');
        $vague = $this->transaction('Перевод по счёту');
        $this->em->flush();
        $this->request();
        $taskId = $this->ai->requests[0]['taskId'];

        $result = $this->aiResult($taskId, [
            [(string) $rentTx->getId(), (string) $this->rent->getId(), 0.93],
            [(string) $vague->getId(), null, 0.0],
        ]);
        $this->consume($result);
        $this->consume($result);

        self::assertSame(Status::SUGGESTED, $this->suggestionFor($rentTx)->getStatus());
        self::assertSame('0.9300', $this->suggestionFor($rentTx)->getConfidence());
        self::assertSame(Status::NO_ANSWER, $this->suggestionFor($vague)->getStatus());

        $shown = $this->query()->shownSuggestions((string) $this->company->getId(), [(string) $rentTx->getId(), (string) $vague->getId()]);
        self::assertSame(['Аренда'], array_map(static fn ($v) => $v->categoryName, array_values($shown)));
        self::assertSame(93, $shown[(string) $rentTx->getId()]->confidencePercent);
    }

    public function testAnswerForOperationCategorizedMeanwhileOrForeignCategoryIsStale(): void
    {
        $meanwhile = $this->transaction('Оплата аренды');
        $foreign = $this->transaction('Оплата аренды склада');
        $this->em->flush();
        $this->request();
        $taskId = $this->ai->requests[0]['taskId'];

        $meanwhile->setCashflowCategory($this->revenue);
        $this->em->flush();

        $this->consume($this->aiResult($taskId, [
            [(string) $meanwhile->getId(), (string) $this->rent->getId(), 0.9],
            [(string) $foreign->getId(), Uuid::uuid4()->toString(), 0.9],
        ]));

        self::assertSame(Status::STALE, $this->suggestionFor($meanwhile)->getStatus());
        self::assertSame(Status::STALE, $this->suggestionFor($foreign)->getStatus());
        self::assertSame('category_unavailable', $this->suggestionFor($foreign)->getErrorCode());
    }

    public function testRejectedTaskFailsRowsAndOperationIsRequestedAgain(): void
    {
        $tx = $this->transaction('Оплата аренды');
        $this->em->flush();
        $this->request();

        $this->consume($this->aiResult($this->ai->requests[0]['taskId'], [], AiResultStatus::REJECTED));
        self::assertSame(Status::FAILED, $this->suggestionFor($tx)->getStatus());

        self::assertSame(1, $this->request()->requested);
    }

    public function testTaskWithoutAnswerIsSentAgainWithSameKeyAndPayloadAndLateAnswerIsAccepted(): void
    {
        $tx = $this->transaction('Оплата аренды офиса');
        $this->em->flush();
        $this->request();
        // Категории поменялись после постановки: переотправка всё равно несёт исходный payload.
        $this->em->persist($this->category('Новая статья', PaymentPlanType::OUTFLOW));
        $this->em->flush();

        $this->clock->modify('+30 minutes');
        self::assertSame(0, $this->request()->redispatched, 'рано: последняя отправка меньше часа назад');

        $this->clock->modify('+1 hour');
        $result = $this->request();

        self::assertSame(1, $result->redispatched);
        self::assertSame(0, $result->requested, 'операция с открытой подсказкой не ставится заново');
        self::assertCount(2, $this->ai->requests);
        self::assertSame($this->ai->requests[0]['key'], $this->ai->requests[1]['key']);
        self::assertSame($this->ai->requests[0]['payload'], $this->ai->requests[1]['payload']);
        self::assertSame($this->ai->requests[0]['taskId'], $this->ai->requests[1]['taskId']);

        $this->consume($this->aiResult($this->ai->requests[0]['taskId'], [[(string) $tx->getId(), (string) $this->rent->getId(), 0.9]]));
        self::assertSame(Status::SUGGESTED, $this->suggestionFor($tx)->getStatus());

        // Ответ получен: запись задачи больше не нужна, переотправок нет.
        $this->clock->modify('+2 hours');
        self::assertSame(0, $this->request()->redispatched);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_categorization_task'));
    }

    public function testRequestWithoutAnswerExpiresAfterAWeekWithoutCountingAsFailedAttempt(): void
    {
        $tx = $this->transaction('Оплата аренды');
        $this->em->flush();

        for ($i = 0; $i < 4; ++$i) {
            $this->request();
            $this->clock->modify('+8 days');
        }
        $result = $this->request();

        // Четыре истечения подряд не исключили операцию (лимит MAX_FAILED_ATTEMPTS — для сбоев ответа).
        self::assertSame(1, $result->expired);
        self::assertSame(1, $result->requested);
        self::assertSame(Status::REQUESTED, $this->suggestionFor($tx)->getStatus());
        self::assertSame(4, (int) $this->connection->fetchOne("SELECT count(*) FROM cash_category_suggestion WHERE error_code = 'expired'"));
    }

    public function testAcceptAfterDecisionCommittedByAnotherRequestKeepsThatDecision(): void
    {
        $tx = $this->transaction('Оплата аренды офиса');
        $suggestion = $this->suggested($tx, $this->rent);
        // Параллельный «Отклонить» закоммитил решение, пока эта копия подсказки лежала в памяти.
        $this->connection->executeStatement(
            "UPDATE cash_category_suggestion SET status = 'dismissed' WHERE id = ?",
            [$suggestion->getId()],
        );

        try {
            $this->accept($suggestion);
            self::fail('решение, принятое другим запросом, не перезаписывается');
        } catch (CashCategorySuggestionStateException) {
        }

        $this->em->clear();
        self::assertNull($this->em->find(CashTransaction::class, $tx->getId())?->getCashflowCategory());
        self::assertSame(Status::DISMISSED, $this->em->find(CashCategorySuggestion::class, $suggestion->getId())?->getStatus());
    }

    public function testDismissAfterAcceptCommittedByAnotherRequestIsRefused(): void
    {
        $tx = $this->transaction('Оплата аренды офиса');
        $suggestion = $this->suggested($tx, $this->rent);
        $this->connection->executeStatement(
            "UPDATE cash_category_suggestion SET status = 'accepted' WHERE id = ?",
            [$suggestion->getId()],
        );

        $this->expectException(CashCategorySuggestionStateException::class);
        $this->dismissAction()((string) $this->company->getId(), $suggestion->getId(), (string) $this->user->getId());
    }

    public function testAcceptSetsCategoryLikeManualEditAndRecordsDecision(): void
    {
        $tx = $this->transaction('Оплата аренды офиса', $this->unallocated);
        $suggestion = $this->suggested($tx, $this->rent);

        $outcome = $this->accept($suggestion);

        self::assertSame(CashCategorySuggestionDecisionOutcome::ACCEPTED, $outcome);
        $this->em->clear();
        $fresh = $this->em->find(CashTransaction::class, $tx->getId());
        self::assertNotNull($fresh);
        self::assertSame($this->rent->getId(), $fresh->getCashflowCategory()?->getId());
        self::assertCount(1, $fresh->getSplits());
        $split = $fresh->getSplits()->first();
        self::assertNotFalse($split);
        self::assertSame(CashTransactionSplitSource::MANUAL, $split->getSource());
        $saved = $this->em->find(CashCategorySuggestion::class, $suggestion->getId());
        self::assertSame(Status::ACCEPTED, $saved?->getStatus());
        self::assertSame((string) $this->user->getId(), $saved->getDecidedByUserId());
    }

    public function testAcceptNeverOverwritesCategoryChosenByHuman(): void
    {
        $tx = $this->transaction('Оплата аренды офиса');
        $suggestion = $this->suggested($tx, $this->rent);
        $tx->setCashflowCategory($this->revenue);
        $this->em->flush();

        self::assertSame(CashCategorySuggestionDecisionOutcome::STALE, $this->accept($suggestion));
        $this->em->clear();
        self::assertSame($this->revenue->getId(), $this->em->find(CashTransaction::class, $tx->getId())?->getCashflowCategory()?->getId());
    }

    public function testAcceptInLockedPeriodIsRefused(): void
    {
        $tx = $this->transaction('Оплата аренды офиса', null, new \DateTimeImmutable('-10 days'));
        $suggestion = $this->suggested($tx, $this->rent);
        $this->company->setFinanceLockBefore(new \DateTimeImmutable('-5 days'));
        $this->em->flush();

        // Закрытый период исключён уже из выборки «без статьи»: подсказка устаревает, статья не ставится.
        self::assertSame(CashCategorySuggestionDecisionOutcome::STALE, $this->accept($suggestion));
        $this->em->clear();
        self::assertNull($this->em->find(CashTransaction::class, $tx->getId())?->getCashflowCategory());
    }

    public function testDismissKeepsOperationUncategorizedAndCountsInPrecision(): void
    {
        $accepted = $this->transaction('Оплата аренды');
        $dismissed = $this->transaction('Аренда?');
        $corrected = $this->transaction('Аренда склада');
        $a = $this->suggested($accepted, $this->rent);
        $d = $this->suggested($dismissed, $this->rent);
        $this->suggested($corrected, $this->rent);

        $this->accept($a);
        $this->dismissAction()((string) $this->company->getId(), $d->getId(), (string) $this->user->getId());
        $this->em->clear();
        $correctedTx = $this->em->find(CashTransaction::class, $corrected->getId());
        self::assertNotNull($correctedTx);
        $correctedTx->setCashflowCategory($this->em->find(CashflowCategory::class, $this->revenue->getId()));
        $this->em->flush();

        $stats = $this->query()->stats((string) $this->company->getId());
        self::assertSame(1, $stats->count('accepted'));
        self::assertSame(1, $stats->count('dismissed'));
        self::assertSame(1, $stats->corrected);
        self::assertEqualsWithDelta(1 / 3, $stats->precision(), 0.0001);
        self::assertNull($this->em->find(CashTransaction::class, $dismissed->getId())?->getCashflowCategory());
    }

    private function category(string $name, ?PaymentPlanType $type, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $this->company);
        $category->setName($name);
        $category->setOperationType($type);
        $category->setParent($parent);

        return $category;
    }

    private function transaction(string $description, ?CashflowCategory $category = null, ?\DateTimeImmutable $occurredAt = null, bool $transfer = false): CashTransaction
    {
        $builder = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->withMoneyAccount($this->account)
            ->withDirection(CashDirection::OUTFLOW)
            ->withAmount('85000.00')
            ->withCashflowCategory($category);
        if ($transfer) {
            $builder = $builder->asTransfer();
        }
        $tx = $builder->build();
        $tx->setOccurredAt($occurredAt ?? new \DateTimeImmutable('-3 days'));
        $tx->setDescription($description);
        $this->em->persist($tx);

        return $tx;
    }

    private function suggested(CashTransaction $tx, CashflowCategory $category): CashCategorySuggestion
    {
        $suggestion = new CashCategorySuggestion((string) $this->company->getId(), (string) $tx->getId(), Uuid::uuid4()->toString(), new \DateTimeImmutable());
        $suggestion->suggest((string) $category->getId(), 0.9, 'accepted', 'stub/categorize', 'categorize_transactions/v1', new \DateTimeImmutable());
        $this->em->persist($suggestion);
        $this->em->flush();

        return $suggestion;
    }

    private function request(): \App\Cash\Application\DTO\CashAiCategorizationRequestResult
    {
        $action = new RequestCashAiCategorizationAction(
            $this->query(),
            $this->repository(),
            self::getContainer()->get(CashCategorizationTaskRepository::class),
            $this->ai,
            $this->em,
            $this->clock,
            new NullLogger(),
        );

        return $action((string) $this->company->getId(), 200);
    }

    private function accept(CashCategorySuggestion $suggestion): CashCategorySuggestionDecisionOutcome
    {
        $container = self::getContainer();
        $action = new AcceptCashCategorySuggestionAction(
            $this->em,
            $this->repository(),
            $container->get(\App\Cash\Repository\Transaction\CashTransactionRepository::class),
            $container->get(\App\Cash\Repository\Transaction\CashflowCategoryRepository::class),
            $this->query(),
            $container->get(\App\Cash\Service\Transaction\CashTransactionService::class),
            $container->get(\App\Cash\Service\Transaction\CashTransactionSplitSynchronizer::class),
            $container->get(EventDispatcherInterface::class),
            $this->clock,
        );

        return $action((string) $this->company->getId(), $suggestion->getId(), (string) $this->user->getId());
    }

    private function dismissAction(): DismissCashCategorySuggestionAction
    {
        return new DismissCashCategorySuggestionAction($this->em, $this->repository(), $this->clock);
    }

    private function consume(AiTaskResult $result): void
    {
        $consumer = new CashCategorizationResultConsumer($this->repository(), $this->query(), $this->clock, new NullLogger());
        $this->em->wrapInTransaction(static fn () => $consumer->consume($result));
        $this->em->clear();
    }

    /**
     * @param list<array{0: string, 1: ?string, 2: float}> $items
     */
    private function aiResult(string $taskId, array $items, AiResultStatus $status = AiResultStatus::ACCEPTED): AiTaskResult
    {
        $rejected = AiResultStatus::REJECTED === $status;

        return new AiTaskResult(
            taskId: $taskId,
            taskType: RequestCashAiCategorizationAction::TASK_TYPE,
            companyId: (string) $this->company->getId(),
            status: $status,
            output: $rejected ? null : ['items' => array_map(
                static fn (array $i): array => ['operation_id' => $i[0], 'category_id' => $i[1], 'confidence' => $i[2]],
                $items,
            )],
            checks: [],
            confidence: $rejected ? null : 0.9,
            traceId: Uuid::uuid7()->toString(),
            models: ['stub/categorize'],
            promptVersion: 'categorize_transactions/v1',
            inputTokens: 0,
            outputTokens: 0,
            errorCode: null,
            completedAt: new \DateTimeImmutable(),
        );
    }

    private function suggestionFor(CashTransaction $tx): CashCategorySuggestion
    {
        $rows = $this->repository()->findBy(['transactionId' => (string) $tx->getId()], ['requestedAt' => 'DESC']);
        self::assertNotEmpty($rows);

        return $rows[0];
    }

    private function countSuggestions(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM cash_category_suggestion');
    }

    private function query(): CashCategorizationQuery
    {
        return self::getContainer()->get(CashCategorizationQuery::class);
    }

    private function repository(): CashCategorySuggestionRepository
    {
        return self::getContainer()->get(CashCategorySuggestionRepository::class);
    }
}

final class RecordingAiTaskFacade implements AiTaskFacadeInterface
{
    public bool $enabled = true;
    /** @var list<array{taskId: string, taskType: string, payload: array<string, mixed>, key: string}> */
    public array $requests = [];

    public function requestTask(string $companyId, string $taskType, array $payload, string $idempotencyKey, ?string $requestedByUserId = null): string
    {
        $taskId = $this->taskIdFor($companyId, $taskType, $idempotencyKey);
        $this->requests[] = ['taskId' => $taskId, 'taskType' => $taskType, 'payload' => $payload, 'key' => $idempotencyKey];

        return $taskId;
    }

    public function taskIdFor(string $companyId, string $taskType, string $idempotencyKey): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $companyId.$taskType.$idempotencyKey)->toString();
    }

    public function isEnabledForCompany(string $companyId): bool
    {
        return $this->enabled;
    }
}
