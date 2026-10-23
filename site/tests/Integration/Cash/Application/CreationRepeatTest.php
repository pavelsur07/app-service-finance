<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\CreateMoneyFundMovementAction;
use App\Cash\Application\CreatePaymentPlanAction;
use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\Accounts\MoneyFund;
use App\Cash\Entity\Accounts\MoneyFundMovement;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\Accounts\MoneyFundMovementRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanDtoApplier;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyFundBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Ramsey\Uuid\Uuid;

/**
 * Повтор формы создания планового платежа и движения по фонду: ключ повтора = ID записи,
 * повтор и одновременная отправка возвращают первую запись, вторая не создаётся.
 */
final class CreationRepeatTest extends IntegrationTestCase
{
    private Company $company;
    private CashflowCategory $category;
    private MoneyFund $fund;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->company();
        $this->category = new CashflowCategory(Uuid::uuid4()->toString(), $this->company);
        $this->category->setName('Аренда');
        $this->fund = MoneyFundBuilder::aFund()->withId(Uuid::uuid4()->toString())->withCompany($this->company)->build();
        $this->em->persist($this->category);
        $this->em->persist($this->fund);
        $this->em->flush();
    }

    public function testRepeatedPlanSubmissionReturnsFirstPlan(): void
    {
        $key = Uuid::uuid7()->toString();
        $first = self::getContainer()->get(CreatePaymentPlanAction::class)($this->company, $this->planDto($key));
        $this->nextRequest();

        $repeated = self::getContainer()->get(CreatePaymentPlanAction::class)($this->company, $this->planDto($key));

        self::assertSame($key, $first->getId());
        self::assertSame($key, $repeated->getId());
        self::assertSame(1, $this->rowCount('payment_plan'));
    }

    public function testConcurrentPlanSubmissionReturnsPlanOfFirst(): void
    {
        $key = Uuid::uuid7()->toString();
        self::getContainer()->get(CreatePaymentPlanAction::class)($this->company, $this->planDto($key));
        $this->nextRequest();

        // Вторая отправка проверила ключ до commit первой: не нашла план и упёрлась в PK.
        $repository = new class(self::getContainer()->get(ManagerRegistry::class)) extends PaymentPlanRepository {
            public function findOneByIdAndCompanyId(string $id, string $companyId): ?PaymentPlan
            {
                return null;
            }
        };
        $action = new CreatePaymentPlanAction(
            $this->em,
            self::getContainer()->get(PaymentPlanDtoApplier::class),
            $repository,
            self::getContainer()->get(ManagerRegistry::class),
        );

        self::assertSame($key, $action($this->company, $this->planDto($key))->getId());
        self::assertSame(1, $this->rowCount('payment_plan'));
    }

    public function testRepeatedMovementSubmissionReturnsFirstMovement(): void
    {
        $key = Uuid::uuid7()->toString();
        self::getContainer()->get(CreateMoneyFundMovementAction::class)($this->movement($key));
        $this->nextRequest();

        $repeated = self::getContainer()->get(CreateMoneyFundMovementAction::class)($this->movement($key));

        self::assertSame($key, $repeated->getId());
        self::assertSame(1, $this->rowCount('money_fund_movement'));
    }

    public function testConcurrentMovementSubmissionReturnsMovementOfFirst(): void
    {
        $key = Uuid::uuid7()->toString();
        self::getContainer()->get(CreateMoneyFundMovementAction::class)($this->movement($key));
        $this->nextRequest();

        $repository = new class(self::getContainer()->get(ManagerRegistry::class)) extends MoneyFundMovementRepository {
            public function findOneByIdAndCompanyId(string $id, string $companyId): ?MoneyFundMovement
            {
                return null;
            }
        };
        $action = new CreateMoneyFundMovementAction($this->em, $repository, self::getContainer()->get(ManagerRegistry::class));

        self::assertSame($key, $action($this->movement($key))->getId());
        self::assertSame(1, $this->rowCount('money_fund_movement'));
    }

    public function testKeyOfForeignCompanyMovementIsRejected(): void
    {
        $key = Uuid::uuid7()->toString();
        $foreignCompany = $this->company();
        $foreignFund = MoneyFundBuilder::aFund()->withId(Uuid::uuid4()->toString())->withCompany($foreignCompany)->build();
        $this->em->persist($foreignFund);
        $this->em->persist(new MoneyFundMovement($key, $foreignCompany, $foreignFund, new \DateTimeImmutable('2026-08-20'), 100));
        $this->em->flush();
        $this->nextRequest();

        $this->expectException(UniqueConstraintViolationException::class);

        self::getContainer()->get(CreateMoneyFundMovementAction::class)($this->movement($key));
    }

    private function planDto(string $key): PaymentPlanDTO
    {
        $dto = new PaymentPlanDTO();
        $dto->idempotencyKey = $key;
        $dto->plannedAt = new \DateTimeImmutable('2026-08-20');
        $dto->amount = '1500.00';
        $dto->cashflowCategory = $this->category;

        return $dto;
    }

    private function movement(string $key): MoneyFundMovement
    {
        return new MoneyFundMovement($key, $this->company, $this->fund, new \DateTimeImmutable('2026-08-20'), 150_000);
    }

    /**
     * Следующий HTTP-запрос — свой EntityManager: созданное раньше в нём не висит.
     */
    private function nextRequest(): void
    {
        $this->em->clear();
        $company = $this->em->find(Company::class, $this->company->getId());
        $category = $this->em->find(CashflowCategory::class, $this->category->getId());
        $fund = $this->em->find(MoneyFund::class, $this->fund->getId());
        \assert($company instanceof Company && $category instanceof CashflowCategory && $fund instanceof MoneyFund);
        [$this->company, $this->category, $this->fund] = [$company, $category, $fund];
    }

    private function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE company_id = :company', $table),
            ['company' => $this->company->getId()],
        );
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()
            ->withId(Uuid::uuid4()->toString())
            ->withEmail(sprintf('user+%s@example.test', Uuid::uuid4()->toString()))
            ->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid4()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }
}
