<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Infrastructure;

use App\Cash\Application\DTO\CashAiRuleProposal;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Service\Transaction\CashTransactionSplitSynchronizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

/**
 * Предложения автоправил по ИИ-подсказкам (docs/tasks/ai/stages/stage-2.md, 2.4).
 */
final class CashAiRuleProposalQueryTest extends IntegrationTestCase
{
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $rent;
    private CashflowCategory $utilities;
    private int $counterparties = 0;
    private int $day = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()->withEmail('ai-rules@example.com')->build();
        $this->company = CompanyBuilder::aCompany()->withOwner($user)->withName('Rules')->build();
        $this->em->persist($user);
        $this->em->persist($this->company);
        $this->account = $this->account($this->company);
        $this->rent = $this->category($this->company, 'Аренда');
        $this->utilities = $this->category($this->company, 'Коммунальные');
        $this->em->flush();
    }

    public function testRepeatedHumanConfirmationsBecomeAProposal(): void
    {
        $landlord = $this->counterparty('Арендодатель');
        foreach ([1, 2, 3] as $_) {
            $this->operation($landlord, 'accepted', $this->rent, $this->rent);
        }
        // Подсказка ещё висела, а человек сам поставил ту же статью — подтверждение; операция последняя.
        $latest = $this->operation($landlord, 'suggested', $this->rent, $this->rent);
        $this->operation($landlord, 'suggested', $this->rent);
        $this->operation($landlord, 'suggested', $this->rent);

        $proposals = $this->proposals();

        self::assertCount(1, $proposals);
        $proposal = $proposals[0];
        self::assertSame((string) $landlord->getId(), $proposal->counterpartyId);
        self::assertSame('Арендодатель', $proposal->counterpartyName);
        self::assertSame(CashDirection::OUTFLOW, $proposal->direction);
        self::assertSame($this->rent->getId(), $proposal->categoryId);
        self::assertSame(4, $proposal->confirmed);
        self::assertSame(2, $proposal->pending);
        self::assertSame((string) $latest->getId(), $proposal->sampleTransactionId);
    }

    public function testBelowThresholdIsNotProposed(): void
    {
        $landlord = $this->counterparty('Арендодатель');
        $this->operation($landlord, 'accepted', $this->rent, $this->rent);
        $this->operation($landlord, 'accepted', $this->rent, $this->rent);

        self::assertSame([], $this->proposals());
    }

    public function testDismissedSuggestionOfTheSameCategoryBlocksTheProposal(): void
    {
        $landlord = $this->counterparty('Арендодатель');
        foreach ([1, 2, 3] as $_) {
            $this->operation($landlord, 'accepted', $this->rent, $this->rent);
        }
        $this->operation($landlord, 'dismissed', $this->rent);

        self::assertSame([], $this->proposals());
    }

    public function testPeopleChoosingDifferentCategoriesBlocksTheProposal(): void
    {
        $landlord = $this->counterparty('Арендодатель');
        foreach ([1, 2, 3] as $_) {
            $this->operation($landlord, 'accepted', $this->rent, $this->rent);
        }
        $this->operation($landlord, 'auto_applied', $this->rent, $this->utilities);

        self::assertSame([], $this->proposals());
    }

    public function testCorrectionsToAnotherCategoryAreDecisionsAgainst(): void
    {
        // Только исправления: ИИ трижды ошибся — ни статья подсказки, ни статья человека не предлагаются.
        $landlord = $this->counterparty('Арендодатель');
        foreach ([1, 2, 3] as $_) {
            $this->operation($landlord, 'suggested', $this->utilities, $this->rent);
        }
        // Одно исправление среди подтверждений тоже закрывает группу.
        $mixed = $this->counterparty('Смешанный');
        foreach ([1, 2, 3] as $_) {
            $this->operation($mixed, 'accepted', $this->rent, $this->rent);
        }
        $this->operation($mixed, 'suggested', $this->utilities, $this->rent);

        self::assertSame([], $this->proposals());
    }

    public function testAutoAppliedWithoutHumanIsNotAConfirmation(): void
    {
        $landlord = $this->counterparty('Арендодатель');
        foreach ([1, 2, 3] as $_) {
            $tx = $this->operation($landlord, 'auto_applied', $this->rent, $this->rent, CashTransactionSplitSource::AUTO);
            // Правка другого поля через форму синхронизирует разбивку как ручную — источник
            // остаётся `auto`, пока статья та же.
            $tx->setDescription('Аренда за месяц');
            self::getContainer()->get(CashTransactionSplitSynchronizer::class)->sync($tx, CashTransactionSplitSource::MANUAL);
            $this->em->flush();
        }

        self::assertSame([], $this->proposals());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM cash_transaction_split WHERE company_id = ? AND source = ?', [(string) $this->company->getId(), CashTransactionSplitSource::MANUAL->value]));
    }

    public function testExistingRuleOrArchivedCounterpartyIsNotProposed(): void
    {
        $covered = $this->counterparty('С правилом');
        $archived = $this->counterparty('В архиве', archived: true);
        foreach ([$covered, $archived] as $counterparty) {
            foreach ([1, 2, 3] as $_) {
                $this->operation($counterparty, 'accepted', $this->rent, $this->rent);
            }
        }
        $rule = new CashTransactionAutoRule(Uuid::uuid4()->toString(), $this->company, 'С правилом → Аренда', CashTransactionAutoRuleAction::FILL, CashTransactionAutoRuleOperationType::OUTFLOW, $this->rent);
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::COUNTERPARTY,
            operator: CashTransactionAutoRuleConditionOperator::EQUAL,
            counterparty: $covered,
        ));
        $this->em->persist($rule);
        $this->em->flush();

        self::assertSame([], $this->proposals());
    }

    public function testOtherCompanyDataIsNotProposed(): void
    {
        $owner = UserBuilder::aUser()->withIndex(9403)->withEmail('ai-rules-2@example.com')->build();
        $other = CompanyBuilder::aCompany()->withIndex(9402)->withOwner($owner)->withName('Other')->build();
        $this->em->persist($owner);
        $this->em->persist($other);
        $this->account = $this->account($other);
        $foreignRent = $this->category($other, 'Аренда');
        $this->em->flush();
        $landlord = $this->counterparty('Чужой арендодатель', company: $other);
        foreach ([1, 2, 3] as $_) {
            $this->operation($landlord, 'accepted', $foreignRent, $foreignRent, company: $other);
        }

        self::assertSame([], $this->proposals());
        self::assertCount(1, self::getContainer()->get(CashCategorizationQuery::class)->findRuleProposals((string) $other->getId()));
    }

    /** @return list<CashAiRuleProposal> */
    private function proposals(): array
    {
        return self::getContainer()->get(CashCategorizationQuery::class)->findRuleProposals((string) $this->company->getId());
    }

    /**
     * Операция контрагента с ИИ-подсказкой `$suggested` в статусе `$status`; `$human` — статья,
     * которую операция получила потом (разбивка `$source`).
     */
    private function operation(
        Counterparty $counterparty,
        string $status,
        CashflowCategory $suggested,
        ?CashflowCategory $human = null,
        CashTransactionSplitSource $source = CashTransactionSplitSource::MANUAL,
        ?Company $company = null,
    ): CashTransaction {
        $company ??= $this->company;
        $tx = CashTransactionBuilder::aCashTransaction()
            ->withId(Uuid::uuid4()->toString())
            ->forCompany($company)
            ->withMoneyAccount($this->account)
            ->withDirection(CashDirection::OUTFLOW)
            ->withAmount('85000.00')
            ->build();
        $tx->setOccurredAt(new \DateTimeImmutable(sprintf('-%d days', 30 - ++$this->day)));
        $tx->setCounterparty($counterparty);
        $this->em->persist($tx);
        $this->em->flush();
        if (null !== $human) {
            $tx->setCashflowCategory($human);
            self::getContainer()->get(CashTransactionSplitSynchronizer::class)->sync($tx, $source);
            $this->em->flush();
        }
        $this->connection->insert('cash_category_suggestion', [
            'id' => Uuid::uuid7()->toString(),
            'company_id' => (string) $company->getId(),
            'transaction_id' => (string) $tx->getId(),
            'task_id' => Uuid::uuid4()->toString(),
            'status' => $status,
            'category_id' => (string) $suggested->getId(),
            'confidence' => '0.9500',
            'verdict' => 'accepted',
            'requested_at' => '2026-10-01 12:00:00',
        ]);

        return $tx;
    }

    private function counterparty(string $name, bool $archived = false, ?Company $company = null): Counterparty
    {
        $builder = CounterpartyBuilder::aCounterparty()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company ?? $this->company)
            ->withName($name)
            ->withInn(sprintf('77%08d', ++$this->counterparties));
        $counterparty = ($archived ? $builder->asArchived() : $builder)->build();
        $this->em->persist($counterparty);
        $this->em->flush();

        return $counterparty;
    }

    private function account(Company $company): MoneyAccount
    {
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($account);

        return $account;
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $category->setOperationType(PaymentPlanType::OUTFLOW);
        $this->em->persist($category);

        return $category;
    }
}
