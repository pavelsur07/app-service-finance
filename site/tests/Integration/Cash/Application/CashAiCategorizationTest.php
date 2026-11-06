<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AcceptCashCategorySuggestionAction;
use App\Cash\Application\DismissCashCategorySuggestionAction;
use App\Cash\Application\DTO\CashCategorizationExample;
use App\Cash\Application\RequestCashAiCategorizationAction;
use App\Cash\Application\Service\CashCategorizationPayloadMapper;
use App\Cash\Application\Service\CashCategorizationResultConsumer;
use App\Cash\Application\Service\CashCategorySuggestionApplier;
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
use App\ServiceGateway\Application\DTO\AiCompanyBudget;
use App\ServiceGateway\Application\DTO\AiCompanyOverview;
use App\ServiceGateway\Application\DTO\AiTaskResult;
use App\ServiceGateway\Enum\AiResultStatus;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
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

    public function testExhaustedBudgetRequestsNothingNew(): void
    {
        $this->transaction('Оплата аренды');
        $this->em->flush();
        $this->ai->spentRub = 500.0;

        $result = $this->request();

        self::assertSame('budget_exceeded', $result->skippedReason);
        self::assertSame([], $this->ai->requests);
        self::assertSame(0, $this->countSuggestions());
    }

    public function testNewBatchesWaitWhilePreviousRequestsHaveNoAnswer(): void
    {
        $this->transaction('Оплата аренды');
        $this->em->flush();
        self::assertSame(1, $this->request()->requested);

        // Импорт встал: через час ответа нет, появилась новая операция — новых пачек не будет,
        // их стоимость не попала бы в лимит.
        $this->transaction('Аренда склада');
        $this->em->flush();
        $this->clock->modify('+1 hour');
        $result = $this->request();

        self::assertSame('awaiting_results', $result->skippedReason);
        self::assertSame(0, $result->requested);
    }

    public function testBatchCarriesSimilarCompanyDecisionsButNotAcceptedAiSuggestions(): void
    {
        $this->transaction('Оплата аренды склада за сентябрь', $this->rent, new \DateTimeImmutable('-30 days'));
        $byAi = $this->transaction('Оплата аренды парковки за август', $this->rent, new \DateTimeImmutable('-40 days'));
        $autoApplied = $this->transaction('Оплата аренды стоянки за июль', $this->rent, new \DateTimeImmutable('-50 days'));
        $this->transaction('Банковская комиссия за обслуживание', $this->rent, new \DateTimeImmutable('-20 days'));
        $this->transaction('Оплата аренды офиса в прошлом году', $this->rent, new \DateTimeImmutable('-400 days'));
        $this->transaction('Оплата аренды офиса за октябрь');
        $this->em->flush();
        // Статью парковки поставил человек, приняв подсказку ИИ, стоянки — автоприменение ИИ: модель не должна
        // учиться на своих ответах.
        $this->suggested($byAi, $this->rent)->accept((string) $this->user->getId(), new \DateTimeImmutable());
        $this->suggested($autoApplied, $this->rent)->autoApply(new \DateTimeImmutable());
        $this->em->flush();

        $this->request();

        self::assertSame([[
            'direction' => 'outflow',
            'description' => 'Оплата аренды склада за сентябрь',
            'counterparty' => null,
            'category_id' => (string) $this->rent->getId(),
        ]], $this->ai->requests[0]['payload']['examples']);
    }

    public function testSameCounterpartyAndDirectionWinOverWordMatches(): void
    {
        $north = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid4()->toString())->withCompany($this->company)->withName('ООО Север')->build();
        $this->em->persist($north);
        // Три примера без контрагента с общими словами и три поступления от того же контрагента — шум.
        foreach ([2, 3, 4] as $n) {
            $this->transaction(sprintf('Оплата по счёту %d', $n), $this->rent, new \DateTimeImmutable(sprintf('-%d days', 10 + $n)));
            $income = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($this->company)
                ->withMoneyAccount($this->account)->withDirection(CashDirection::INFLOW)->withAmount('100.00')
                ->withCashflowCategory($this->revenue)->build();
            $income->setOccurredAt(new \DateTimeImmutable(sprintf('-%d days', $n)));
            $income->setDescription(sprintf('Оплата по счёту %d от покупателя', $n));
            $income->setCounterparty($north);
            $this->em->persist($income);
        }
        $this->transaction('Поставка материалов', $this->rent, new \DateTimeImmutable('-60 days'))->setCounterparty($north);
        $this->transaction('Оплата по счёту 1')->setCounterparty($north);
        $this->em->flush();

        $this->request();

        $examples = $this->ai->requests[0]['payload']['examples'];
        self::assertSame('Поставка материалов', $examples[0]['description']);
        self::assertSame(['outflow'], array_values(array_unique(array_column($examples, 'direction'))));
    }

    public function testBatchWithoutSimilarDecisionsKeepsThePreviousPayloadShape(): void
    {
        $this->transaction('Банковская комиссия за обслуживание', $this->rent, new \DateTimeImmutable('-20 days'));
        $this->transaction('Оплата аренды офиса за октябрь');
        $this->em->flush();

        $this->request();

        self::assertArrayNotHasKey('examples', $this->ai->requests[0]['payload']);
    }

    public function testExampleWithCategoryOutsideTheBatchTreeIsDropped(): void
    {
        $mapper = new CashCategorizationPayloadMapper();
        $categories = [new \App\Cash\Application\DTO\CashCategorizationCategory((string) $this->rent->getId(), 'Расходы / Аренда', 'OUTFLOW')];
        $operation = new \App\Cash\Application\DTO\CashCategorizationOperation(Uuid::uuid4()->toString(), 'OUTFLOW', '10.00', 'RUB', new \DateTimeImmutable(), 'Аренда', null);

        $payload = $mapper->payload($categories, [$operation], [
            new CashCategorizationExample('OUTFLOW', 'Аренда склада', null, (string) $this->rent->getId()),
            new CashCategorizationExample('OUTFLOW', 'Архивная статья', null, Uuid::uuid4()->toString()),
        ]);

        self::assertSame(['Аренда склада'], array_column($payload['examples'] ?? [], 'description'));
    }

    public function testNoAnswerOfPreviousPromptIsAskedAgainExactlyOnce(): void
    {
        $old = $this->transaction('Оплата по счёту 15 от 01.10.2026');
        $current = $this->transaction('Оплата по счёту 16 от 02.10.2026');
        $this->em->flush();
        $this->noAnswer($old, 'categorize_transactions/v2');
        $this->noAnswer($current, RequestCashAiCategorizationAction::PROMPT_VERSION);

        $first = $this->request();

        // «Нет ответа» на прошлом промпте — спросить ещё раз; на текущем — не платить повторно.
        self::assertSame(1, $first->requested);
        self::assertSame([(string) $old->getId()], array_column($this->ai->requests[0]['payload']['operations'], 'id'));

        // Повтор тоже без ответа, уже на текущем промпте: больше операция не уходит.
        $this->consume($this->aiResult($this->ai->requests[0]['taskId'], [[(string) $old->getId(), null, 0.0]]));
        $this->clock->modify('+1 hour');
        self::assertSame(0, $this->request()->requested);
        self::assertCount(1, $this->ai->requests);
    }

    public function testOwnFundsTransfersAreNotSentWhileCompanyHasNoSuchDecisions(): void
    {
        $this->transaction('Перевод собственных средств в фонд Налоги с операций. Без НДС');
        $this->transaction('Перевод собственных средств между счетами. НДС не облагается');
        $deposit = $this->transaction('Возврат средств по депозитной сделке № 1 от 01.10.2026 / Внутреннее перемещение собственных средств');
        $advance = $this->transaction('Перевод под отчет, по ведомости 12 от 01.10.2026');
        $empty = $this->transaction('');
        $empty->setDescription(null);
        $this->em->flush();

        $result = $this->request();

        // Свою фразу компания в статьи не разносила: переводы не отправляются; депозит, подотчёт и пустое
        // назначение — обычные операции.
        self::assertSame(3, $result->requested);
        $ids = array_column($this->ai->requests[0]['payload']['operations'], 'id');
        sort($ids);
        $expected = [(string) $deposit->getId(), (string) $advance->getId(), (string) $empty->getId()];
        sort($expected);
        self::assertSame($expected, $ids);
        self::assertSame(2, $this->query()->stats((string) $this->company->getId(), null, new \DateTimeImmutable('-90 days'))->transferLikeSkipped);
    }

    public function testOwnFundsTransfersAreSentWhenCompanyCategorizesThem(): void
    {
        // Компания сама относит такие переводы к статье (у одного кабинета — погашение кредита): решает её история.
        $this->transaction('Перевод собственных средств. НДС не облагается', $this->rent, new \DateTimeImmutable('-300 days'));
        $transfer = $this->transaction('Перевод собственных средств. НДС не облагается');
        $this->em->flush();

        $result = $this->request();

        self::assertSame(1, $result->requested);
        self::assertSame([(string) $transfer->getId()], array_column($this->ai->requests[0]['payload']['operations'], 'id'));
        self::assertSame(0, $this->query()->stats((string) $this->company->getId(), null, new \DateTimeImmutable('-90 days'))->transferLikeSkipped);
    }

    public function testExpiredUnansweredRequestsKeepNewBatchesPaused(): void
    {
        $this->transaction('Оплата аренды');
        $this->em->flush();
        self::assertSame(1, $this->request()->requested);

        // Импорт стоит больше недели: запрос истекает, но та же операция не уходит повторно
        // с новым ключом — сервис мог уже выполнить её платно.
        $this->clock->modify('+8 days');
        $result = $this->request();

        self::assertSame(1, $result->expired);
        self::assertSame('awaiting_results', $result->skippedReason);
        self::assertCount(1, $this->ai->requests);
    }

    public function testCompanyLookbackLimitsCandidateWindow(): void
    {
        $recent = $this->transaction('Аренда за неделю', null, new \DateTimeImmutable('-3 days'));
        $this->transaction('Аренда месячной давности', null, new \DateTimeImmutable('-20 days'));
        $this->em->flush();
        $this->ai->lookbackDays = 7;

        $this->request();

        self::assertSame([$recent->getId()], array_column($this->ai->requests[0]['payload']['operations'], 'id'));
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

        // Каждый цикл: отправка → неделя без ответа → истечение и пауза → пауза кончилась.
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame(1, $this->request()->requested);
            $this->clock->modify('+8 days');
            self::assertSame(1, $this->request()->expired);
            $this->clock->modify('+8 days');
        }
        $result = $this->request();

        // Четыре истечения подряд не исключили операцию (лимит MAX_FAILED_ATTEMPTS — для сбоев ответа).
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

    private function noAnswer(CashTransaction $tx, string $promptVersion): void
    {
        $suggestion = new CashCategorySuggestion((string) $this->company->getId(), (string) $tx->getId(), Uuid::uuid4()->toString(), new \DateTimeImmutable('-1 day'));
        $suggestion->suggest(null, 0.0, 'needs_review', 'stub/categorize', $promptVersion, new \DateTimeImmutable('-1 day'));
        $this->em->persist($suggestion);
        $this->em->flush();
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
            new CashCategorizationPayloadMapper(),
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
            $container->get(CashCategorySuggestionApplier::class),
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
            promptVersion: RequestCashAiCategorizationAction::PROMPT_VERSION,
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
    public float $spentRub = 0.0;
    public int $lookbackDays = 90;
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

    public function enabledCompanyIds(array $companyIds): array
    {
        return $this->enabled ? $companyIds : [];
    }

    public function connectedCompanyIds(): array
    {
        return [];
    }

    public function isServiceEnabled(): bool
    {
        return $this->enabled;
    }

    public function grantedCompanyIds(array $companyIds): array
    {
        return $this->enabledCompanyIds($companyIds);
    }

    public function companyOverview(string $companyId, int $runs = 50): AiCompanyOverview
    {
        throw new \LogicException('not used');
    }

    public function exhaustedCompanyIds(array $companyIds): array
    {
        return [];
    }

    public function budgetFor(string $companyId): AiCompanyBudget
    {
        return new AiCompanyBudget(limitRub: 500.0, spentRub: $this->spentRub, totalLimitRub: 5000.0, totalSpentRub: $this->spentRub, lookbackDays: $this->lookbackDays);
    }

    public function recordRun(string $companyId, string $taskType, int $requested = 0, int $tasks = 0, int $redispatched = 0, int $expired = 0, ?string $skippedReason = null, ?string $error = null): void
    {
    }
}
