<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AutoApplyCashCategorySuggestionsAction;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Application\Service\CashCategorySuggestionApplier;
use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Cash\Domain\Policy\CashAiAutoApplyPolicy;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashCategorySuggestion;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus as Status;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Transaction\CashAiCategorizationSettingRepository;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\CashAiCategorizationSettingBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Автоприменение уверенных ИИ-подсказок (docs/tasks/ai/stages/stage-2.md, 2.3).
 */
final class CashAiAutoApplyTest extends IntegrationTestCase
{
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $rent;
    private CashflowCategory $revenue;
    private RecordingEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()->withEmail('ai-auto@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->withName('Auto')->build();
        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Main', 'RUB');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $this->rent = $this->category('Аренда', PaymentPlanType::OUTFLOW);
        $this->revenue = $this->category('Выручка', PaymentPlanType::INFLOW);
        foreach ([$user, $this->company, $this->account, $this->rent, $this->revenue] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->dispatcher = new RecordingEventDispatcher();
    }

    public function testFlagOffAppliesNothingEvenWithEnoughGoodDecisions(): void
    {
        $this->humanDecisions(right: 100, wrong: 0);
        $tx = $this->transaction();
        $this->suggestion($tx, 0.97, 'accepted');

        $result = $this->autoApply();

        self::assertSame(CashAiAutoApplyPolicy::REASON_DISABLED, $result->closedReason);
        self::assertSame(100, $result->gate->decisions());
        $this->assertUncategorized($tx);
    }

    public function testGateStaysClosedUntilPeopleConfirmEnoughConfidentSuggestions(): void
    {
        $this->enable();
        $this->humanDecisions(right: 99, wrong: 0);
        // Решения по неуверенным подсказкам качество уверенных не подтверждают.
        $this->humanDecisions(right: 50, wrong: 0, confidence: '0.6000');
        $tx = $this->transaction();
        $this->suggestion($tx, 0.97, 'accepted');

        self::assertSame(CashAiAutoApplyPolicy::REASON_TOO_FEW_DECISIONS, $this->autoApply()->closedReason);
        $this->assertUncategorized($tx);
    }

    public function testLowPrecisionKeepsGateClosed(): void
    {
        $this->enable();
        $this->humanDecisions(right: 94, wrong: 6);
        $this->suggestion($this->transaction(), 0.97, 'accepted');

        self::assertSame(CashAiAutoApplyPolicy::REASON_LOW_PRECISION, $this->autoApply()->closedReason);
    }

    public function testOpenGateAppliesOnlyConfidentSuggestionsWithoutReviewAsAutoSplit(): void
    {
        $this->enable();
        $this->humanDecisions(right: 100, wrong: 0);
        $confident = $this->transaction();
        $applied = $this->suggestion($confident, 0.97, 'accepted');
        $unsure = $this->transaction();
        $this->suggestion($unsure, 0.85, 'accepted');
        $review = $this->transaction();
        $this->suggestion($review, 0.99, 'needs_review');

        $result = $this->autoApply();

        self::assertNull($result->closedReason);
        self::assertSame(1, $result->applied);
        $this->em->clear();
        $tx = $this->em->find(CashTransaction::class, $confident->getId());
        self::assertNotNull($tx);
        self::assertSame($this->rent->getId(), $tx->getCashflowCategory()?->getId());
        $split = $tx->getSplits()->first();
        self::assertNotFalse($split);
        self::assertSame(CashTransactionSplitSource::AUTO, $split->getSource());
        $saved = $this->em->find(CashCategorySuggestion::class, $applied->getId());
        self::assertSame(Status::AUTO_APPLIED, $saved?->getStatus());
        self::assertNull($saved->getDecidedByUserId());
        $this->assertUncategorized($unsure);
        $this->assertUncategorized($review);
        self::assertEquals([new CashTransactionsChanged((string) $this->company->getId())], $this->dispatcher->events);

        // Повтор ничего не меняет.
        $this->dispatcher->events = [];
        self::assertSame(0, $this->autoApply()->applied);
        self::assertSame([], $this->dispatcher->events);
    }

    public function testCorrectedAutoApplicationClosesTheGate(): void
    {
        $this->enable();
        $this->humanDecisions(right: 95, wrong: 5);
        $tx = $this->transaction();
        $this->suggestion($tx, 0.97, 'accepted');
        self::assertSame(1, $this->autoApply()->applied);

        // Человек поправил автоприменённую статью: 95 верных из 101 — ниже порога.
        $this->categorize($tx, $this->revenue, CashTransactionSplitSource::MANUAL);

        $result = $this->autoApply();
        self::assertSame(1, $result->gate->autoCorrected);
        self::assertSame(CashAiAutoApplyPolicy::REASON_LOW_PRECISION, $result->closedReason);
    }

    public function testAutomaticCategorizationIsNotAHumanDecision(): void
    {
        $this->enable();
        $this->humanDecisions(right: 99, wrong: 0);
        // Пока подсказка висела, статью проставило автоправило — это не подтверждение людьми.
        $byRule = $this->transaction();
        $this->suggestion($byRule, 0.97, 'accepted');
        $this->categorize($byRule, $this->rent, CashTransactionSplitSource::AUTO);
        // Автоправило сменило автоприменённую статью — это не исправление человеком.
        $overridden = $this->transaction();
        $this->connection->insert('cash_category_suggestion', [
            'id' => Uuid::uuid7()->toString(),
            'company_id' => (string) $this->company->getId(),
            'transaction_id' => (string) $overridden->getId(),
            'task_id' => Uuid::uuid4()->toString(),
            'status' => 'auto_applied',
            'category_id' => (string) $this->rent->getId(),
            'confidence' => '0.9700',
            'verdict' => 'accepted',
            'requested_at' => '2026-10-01 12:00:00',
        ]);
        $this->categorize($overridden, $this->revenue, CashTransactionSplitSource::AUTO);
        $this->suggestion($this->transaction(), 0.97, 'accepted');

        $result = $this->autoApply();

        self::assertSame(CashAiAutoApplyPolicy::REASON_TOO_FEW_DECISIONS, $result->closedReason);
        self::assertSame(99, $result->gate->decisions());
        self::assertSame(0, $result->gate->autoCorrected);
    }

    public function testAutoAppliedCategoryStaysReplaceableByAutoRulesUntilHumanChangesIt(): void
    {
        $this->enable();
        $this->humanDecisions(right: 100, wrong: 0);
        $tx = $this->transaction();
        $this->suggestion($tx, 0.97, 'accepted');
        self::assertSame(1, $this->autoApply()->applied);

        $resolver = self::getContainer()->get(CashTransactionAutoRuleProvenanceResolver::class);
        $this->em->clear();
        $fresh = $this->em->find(CashTransaction::class, $tx->getId());
        self::assertNotNull($fresh);
        self::assertTrue($resolver->resolve($fresh)->isAutoAssigned('cashflowCategory'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM audit_log WHERE entity_id = ? AND diff::jsonb #> \'{changes,cashflowCategory}\' IS NOT NULL',
            [(string) $tx->getId()],
        ));

        $this->categorize($tx, $this->revenue, CashTransactionSplitSource::MANUAL);
        $this->em->clear();
        $fresh = $this->em->find(CashTransaction::class, $tx->getId());
        self::assertNotNull($fresh);
        self::assertFalse($resolver->resolve($fresh)->isAutoAssigned('cashflowCategory'));
    }

    public function testHumanDecisionBeforeTheLockWinsWithoutBreakingTheRun(): void
    {
        $suggestion = $this->suggestion($this->transaction(), 0.97, 'accepted');
        $this->connection->executeStatement("UPDATE cash_category_suggestion SET status = 'dismissed' WHERE id = ?", [$suggestion->getId()]);

        $applier = self::getContainer()->get(CashCategorySuggestionApplier::class);
        $outcome = $this->em->wrapInTransaction(
            fn () => $applier->apply((string) $this->company->getId(), $suggestion->getId(), null, new \DateTimeImmutable()),
        );

        self::assertNull($outcome);
        self::assertTrue($this->em->isOpen());
        self::assertSame('dismissed', $this->connection->fetchOne('SELECT status FROM cash_category_suggestion WHERE id = ?', [$suggestion->getId()]));
    }

    public function testListMarksAutoAppliedCategoryUntilChanged(): void
    {
        $this->enable();
        $this->humanDecisions(right: 100, wrong: 0);
        $tx = $this->transaction();
        $this->suggestion($tx, 0.97, 'accepted');
        $this->autoApply();

        $query = self::getContainer()->get(CashCategorizationQuery::class);
        self::assertSame([(string) $tx->getId() => 97], $query->autoAppliedConfidence((string) $this->company->getId(), [(string) $tx->getId()]));

        $this->categorize($tx, $this->revenue, CashTransactionSplitSource::MANUAL);
        self::assertSame([], $query->autoAppliedConfidence((string) $this->company->getId(), [(string) $tx->getId()]));
    }

    private function autoApply(): \App\Cash\Application\DTO\CashAiAutoApplyResult
    {
        $container = self::getContainer();
        $dispatcher = $this->dispatcher;

        return (new AutoApplyCashCategorySuggestionsAction(
            $container->get(CashAiCategorizationSettingRepository::class),
            $container->get(CashCategorizationQuery::class),
            $container->get(CashCategorySuggestionApplier::class),
            $container->get(AutoRuleDispatchGuard::class),
            $this->em,
            $dispatcher,
            new MockClock(),
            new NullLogger(),
        ))((string) $this->company->getId());
    }

    /** Правка статьи операции: человек — разбивка `manual`, автоправило — `auto`. */
    private function categorize(CashTransaction $tx, CashflowCategory $category, CashTransactionSplitSource $source): void
    {
        $fresh = $this->em->find(CashTransaction::class, $tx->getId());
        self::assertNotNull($fresh);
        $fresh->setCashflowCategory($this->em->find(CashflowCategory::class, $category->getId()));
        self::getContainer()->get(CashTransactionSplitSynchronizer::class)->sync($fresh, $source);
        $this->em->flush();
    }

    private function enable(): void
    {
        $this->em->persist(CashAiCategorizationSettingBuilder::aSetting()->forCompanyId((string) $this->company->getId())->withAutoApply()->build());
        $this->em->flush();
    }

    /**
     * Решения людей по подсказкам: одна операция со статьёй «Аренда», строки с разными task_id.
     */
    private function humanDecisions(int $right, int $wrong, string $confidence = '0.9500'): void
    {
        $tx = $this->transaction($this->rent);
        $this->em->flush();
        foreach ([...array_fill(0, $right, 'accepted'), ...array_fill(0, $wrong, 'dismissed')] as $status) {
            $this->connection->insert('cash_category_suggestion', [
                'id' => Uuid::uuid7()->toString(),
                'company_id' => (string) $this->company->getId(),
                'transaction_id' => (string) $tx->getId(),
                'task_id' => Uuid::uuid4()->toString(),
                'status' => $status,
                'category_id' => (string) $this->rent->getId(),
                'confidence' => $confidence,
                'verdict' => 'accepted',
                'requested_at' => '2026-10-01 12:00:00',
            ]);
        }
    }

    private function suggestion(CashTransaction $tx, float $confidence, string $verdict): CashCategorySuggestion
    {
        $suggestion = new CashCategorySuggestion((string) $this->company->getId(), (string) $tx->getId(), Uuid::uuid4()->toString(), new \DateTimeImmutable());
        $suggestion->suggest((string) $this->rent->getId(), $confidence, $verdict, 'stub/categorize', 'categorize_transactions/v1', new \DateTimeImmutable());
        $this->em->persist($suggestion);
        $this->em->flush();

        return $suggestion;
    }

    private function transaction(?CashflowCategory $category = null): CashTransaction
    {
        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($this->company)
            ->withMoneyAccount($this->account)
            ->withDirection(CashDirection::OUTFLOW)
            ->withAmount('85000.00')
            ->withCashflowCategory($category)
            ->build();
        $tx->setOccurredAt(new \DateTimeImmutable('-3 days'));
        $tx->setDescription('Оплата аренды');
        $this->em->persist($tx);
        $this->em->flush();

        return $tx;
    }

    private function category(string $name, PaymentPlanType $type): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $this->company);
        $category->setName($name);
        $category->setOperationType($type);

        return $category;
    }

    private function assertUncategorized(CashTransaction $tx): void
    {
        $this->em->clear();
        self::assertNull($this->em->find(CashTransaction::class, $tx->getId())?->getCashflowCategory());
    }
}

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return $event;
    }
}
