<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\PaymentPlan;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\PaymentPlan\PaymentPlanMatchRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanMatcher;
use App\Cash\Service\PaymentPlan\PaymentPlanService;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * Один плановый платёж — одно сопоставление: план, который параллельная транзакция заняла после
 * выборки кандидатов, под блокировкой не берётся — достаётся следующий кандидат.
 */
final class PaymentPlanMatchConcurrencyTest extends IntegrationTestCase
{
    private Company $company;
    private MoneyAccount $account;
    private CashflowCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(Uuid::uuid4()->toString().'@example.test')
            ->build();
        $this->company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($user)
            ->build();
        $this->account = new MoneyAccount(Uuid::uuid4()->toString(), $this->company, MoneyAccountType::BANK, 'Счёт', 'RUB');
        $this->account->setOpeningBalance('0');
        $this->account->setOpeningBalanceDate(new \DateTimeImmutable('2024-01-01'));
        $this->category = new CashflowCategory(Uuid::uuid4()->toString(), $this->company);
        $this->category->setName('Аренда');

        foreach ([$user, $this->company, $this->account, $this->category] as $entity) {
            $this->em->persist($entity);
        }
    }

    public function testPlanTakenConcurrentlyIsSkippedForNextCandidate(): void
    {
        $best = $this->plan('100.00');
        $next = $this->plan('101.00');
        $competitor = $this->transaction('100.00');
        $transaction = $this->transaction('100.00');
        $this->em->flush();

        $planRepository = new class(self::getContainer()->get(ManagerRegistry::class), $best, $competitor) extends PaymentPlanRepository {
            private bool $competed = false;

            public function __construct(ManagerRegistry $registry, private PaymentPlan $contested, private CashTransaction $competitor)
            {
                parent::__construct($registry);
            }

            public function lockIfAvailableForMatch(string $planId, string $companyId): bool
            {
                // Параллельная транзакция успела сопоставить лучший план между выборкой
                // кандидатов и блокировкой.
                if (!$this->competed && $planId === $this->contested->getId()) {
                    $this->competed = true;
                    $this->getEntityManager()->getConnection()->insert('payment_plan_match', [
                        'id' => Uuid::uuid4()->toString(),
                        'company_id' => $companyId,
                        'plan_id' => $planId,
                        'transaction_id' => $this->competitor->getId(),
                        'matched_at' => '2024-08-20 10:00:00',
                    ]);
                }

                return parent::lockIfAvailableForMatch($planId, $companyId);
            }
        };

        $matcher = new PaymentPlanMatcher(
            $this->em,
            $planRepository,
            self::getContainer()->get(PaymentPlanMatchRepository::class),
            self::getContainer()->get(PaymentPlanService::class),
        );

        self::assertSame($next, $this->em->wrapInTransaction(
            static fn (): ?PaymentPlan => $matcher->matchForTransaction($transaction),
        ));

        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM payment_plan_match WHERE plan_id = :id',
            ['id' => $best->getId()],
        ));
    }

    public function testDatabaseRejectsSecondMatchOfPlan(): void
    {
        $plan = $this->plan('100.00');
        $first = $this->transaction('100.00');
        $second = $this->transaction('100.00');
        $this->em->flush();

        $insert = fn (CashTransaction $transaction) => $this->connection->insert('payment_plan_match', [
            'id' => Uuid::uuid4()->toString(),
            'company_id' => $this->company->getId(),
            'plan_id' => $plan->getId(),
            'transaction_id' => $transaction->getId(),
            'matched_at' => '2024-08-20 10:00:00',
        ]);
        $insert($first);

        $this->expectException(UniqueConstraintViolationException::class);
        $insert($second);
    }

    private function plan(string $amount): PaymentPlan
    {
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $this->company, $this->category, new \DateTimeImmutable('2024-08-20'), $amount);
        $plan->setStatus(PaymentPlanStatus::APPROVED);
        $this->em->persist($plan);

        return $plan;
    }

    private function transaction(string $amount): CashTransaction
    {
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $this->company,
            $this->account,
            CashDirection::OUTFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable('2024-08-20'),
        );
        $transaction->setCashflowCategory($this->category);
        $this->em->persist($transaction);

        return $transaction;
    }
}
