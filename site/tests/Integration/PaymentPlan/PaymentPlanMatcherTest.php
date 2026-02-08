<?php

declare(strict_types=1);

namespace App\Tests\Integration\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Company\Entity\Company;
use App\Entity\Counterparty;
use App\Company\Enum\CounterpartyType;
use App\Enum\MoneyAccountType;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
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

        $counterpartyA = new Counterparty(Uuid::uuid4()->toString(), $companyA, 'A Counterparty', CounterpartyType::LEGAL_ENTITY);

        $this->em->persist($accountA);
        $this->em->persist($categoryA);
        $this->em->persist($counterpartyA);

        // Context B
        $categoryB = new CashflowCategory(Uuid::uuid4()->toString(), $companyB);
        $categoryB->setName('B Category');

        $counterpartyB = new Counterparty(Uuid::uuid4()->toString(), $companyB, 'B Counterparty', CounterpartyType::LEGAL_ENTITY);

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
        self::assertNull($this->matchRepository->findOneByTransaction($transactionFromDb));
    }
}
