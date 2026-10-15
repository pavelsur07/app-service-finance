<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Company\Domain\Service\CounterpartyNameNormalizer;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class PaymentPlanMatcherTest extends IntegrationTestCase
{
    private PaymentPlanMatcher $matcher;
    private PaymentPlanMatchRepository $matchRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = self::getContainer()->get(PaymentPlanMatcher::class);
        $this->matchRepository = self::getContainer()->get(PaymentPlanMatchRepository::class);
    }

    public function testDoesNotMatchPlansFromAnotherCompany(): void
    {
        // Company A
        $userA = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('a@example.test')
            ->build();

        $companyA = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($userA)
            ->withName('Company A')
            ->build();

        // Company B
        $userB = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('b@example.test')
            ->build();

        $companyB = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($userB)
            ->withName('Company B')
            ->build();

        $this->em->persist($userA);
        $this->em->persist($companyA);
        $this->em->persist($userB);
        $this->em->persist($companyB);

        // Context A
        $accountA = new MoneyAccount(Uuid::uuid4()->toString(), $companyA, MoneyAccountType::BANK, 'A Account', 'RUB');
        $accountA->setOpeningBalance('0');
        $accountA->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));

        $categoryA = new CashflowCategory(Uuid::uuid4()->toString(), $companyA);
        $categoryA->setName('A Category');

        $counterpartyA = new Counterparty(Uuid::uuid4()->toString(), $companyA, (new CounterpartyNameNormalizer())->normalize('A Counterparty'), CounterpartyType::LEGAL_ENTITY);

        $this->em->persist($accountA);
        $this->em->persist($categoryA);
        $this->em->persist($counterpartyA);

        // Context B
        $categoryB = new CashflowCategory(Uuid::uuid4()->toString(), $companyB);
        $categoryB->setName('B Category');

        $counterpartyB = new Counterparty(Uuid::uuid4()->toString(), $companyB, (new CounterpartyNameNormalizer())->normalize('B Counterparty'), CounterpartyType::LEGAL_ENTITY);

        $this->em->persist($categoryB);
        $this->em->persist($counterpartyB);

        // Transaction in A
        $txDate = new \DateTimeImmutable('2024-08-20');
        $transactionA = new CashTransaction(
            Uuid::uuid4()->toString(),
            $companyA,
            $accountA,
            CashDirection::OUTFLOW,
            '75.00',
            'RUB',
            $txDate
        );
        $transactionA->setCashflowCategory($categoryA);
        $transactionA->setCounterparty($counterpartyA);

        $this->em->persist($transactionA);

        // Plan in B (same date/amount, but другой company) – НЕ должен матчиться
        $planB = new PaymentPlan(Uuid::uuid4()->toString(), $companyB, $categoryB, $txDate, '75.00');
        $planB->setStatus(PaymentPlanStatusEnum::APPROVED);
        $planB->setCounterparty($counterpartyB);

        $this->em->persist($planB);

        $this->em->flush();
        $this->em->clear();

        /** @var CashTransaction $transactionFromDb */
        $transactionFromDb = $this->em->getRepository(CashTransaction::class)->find($transactionA->getId());
        self::assertNotNull($transactionFromDb);

        $result = $this->matcher->matchForTransaction($transactionFromDb);

        self::assertNull($result);
        self::assertNull($this->matchRepository->findOneByTransactionAndCompanyId($transactionFromDb, (string) $transactionFromDb->getCompany()->getId()));
    }

    public function testDoesNotMatchCurrencylessPlanToNonRubTransaction(): void
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail('payment-plan-currency@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->withName('Payment plan currency company')
            ->build();
        $account = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $company,
            MoneyAccountType::BANK,
            'USD account',
            'USD',
        );
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Payments');
        $occurredAt = new \DateTimeImmutable('2024-08-20');
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '75.00',
            'USD',
            $occurredAt,
        );
        $transaction->setCashflowCategory($category);
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $category, $occurredAt, '75.00');
        $plan->setStatus(PaymentPlanStatusEnum::APPROVED);

        foreach ([$user, $company, $account, $category, $transaction, $plan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        self::assertNull($this->matcher->matchForTransaction($transaction));
        self::assertNull($this->matchRepository->findOneByTransactionAndCompanyId($transaction, (string) $transaction->getCompany()->getId()));
        self::assertSame(PaymentPlanStatusEnum::APPROVED, $plan->getStatus());
    }

    /**
     * Допуск — «разница больше 5% от плана — не сопоставлять». Во float
     * 5.03 / 100.60 давало 0.0500…01 > 0.05, и план ровно на границе
     * отбрасывался; в минорных единицах граница точная.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function tolerance5PercentProvider(): iterable
    {
        yield 'ровно 5% — сопоставляется' => ['105.63', true];
        yield 'на копейку больше 5% — нет' => ['105.64', false];
        yield 'ровно 5% вниз — сопоставляется' => ['95.57', true];
        yield 'на копейку меньше −5% — нет' => ['95.56', false];
    }

    #[DataProvider('tolerance5PercentProvider')]
    public function testFivePercentToleranceBoundaryIsExact(string $transactionAmount, bool $matches): void
    {
        $user = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('payment-plan-tolerance@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($user)->withName('Tolerance company')->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'RUB account', 'RUB');
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName('Payments');
        $occurredAt = new \DateTimeImmutable('2024-08-20');
        $transaction = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, $transactionAmount, 'RUB', $occurredAt);
        $transaction->setCashflowCategory($category);
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $category, $occurredAt, '100.60');
        $plan->setStatus(PaymentPlanStatusEnum::APPROVED);

        foreach ([$user, $company, $account, $category, $transaction, $plan] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $result = $this->matcher->matchForTransaction($transaction);

        if ($matches) {
            self::assertSame($plan->getId(), $result?->getId());

            // Матчер только persist(): сохраняет вызывающий Action (AddCashTransactionAction).
            $this->em->flush();
            $planId = (string) $plan->getId();
            $this->em->clear();
            self::assertSame(PaymentPlanStatusEnum::PAID, $this->em->find(PaymentPlan::class, $planId)?->getStatus());
        } else {
            self::assertNull($result);
        }
    }
}
