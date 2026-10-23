<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application;

use App\Cash\Application\ChangePaymentPlanStatusAction;
use App\Cash\Application\CreatePaymentPlanAction;
use App\Cash\Application\PostponePaymentPlanAction;
use App\Cash\Application\UpdatePaymentPlanAction;
use App\Cash\DTO\PaymentPlanDTO;
use App\Cash\Entity\PaymentPlan\PaymentPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Cash\Enum\PaymentPlan\PaymentPlanType as PaymentPlanTypeEnum;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Service\PaymentPlan\PaymentPlanDtoApplier;
use App\Cash\Service\PaymentPlan\PaymentPlanService;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Action'ы платёжного календаря (этап 5.2 плана Cash; было PaymentCalendarFacade).
 */
final class PaymentPlanActionsTest extends TestCase
{
    public function testCreatePlanFromDtoMapsProbabilityAndFrozenWithExpectedAtFallback(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $paymentPlanService = new PaymentPlanService();

        $company = $this->createCompany();
        $category = $this->createCategory($company, 'Расходы');

        $dto = new PaymentPlanDTO();
        $dto->plannedAt = new \DateTimeImmutable('2024-05-11');
        $dto->expectedAt = null;
        $dto->amount = '1250.00';
        $dto->cashflowCategory = $category;
        $dto->probability = 50;
        $dto->isFrozen = true;
        $dto->status = PaymentPlanStatusEnum::APPROVED->value;

        $entityManager
            ->expects(self::once())
            ->method('persist');
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $plan = (new CreatePaymentPlanAction($entityManager, new PaymentPlanDtoApplier($paymentPlanService), $this->createMock(PaymentPlanRepository::class), $this->createMock(ManagerRegistry::class)))($company, $dto);

        self::assertSame(50, $plan->getProbability());
        self::assertTrue($plan->isFrozen());
        self::assertSame('2024-05-11', $plan->getExpectedAt()?->format('Y-m-d'));
        self::assertSame(PaymentPlanStatusEnum::APPROVED, $plan->getStatus());
        self::assertSame(PaymentPlanTypeEnum::OUTFLOW, $plan->getType());
    }

    public function testUpdateAppliesDtoAndFlushes(): void
    {
        $company = $this->createCompany();
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $this->createCategory($company, 'Расходы'), new \DateTimeImmutable('2024-05-01'), '100.00');
        $dto = new PaymentPlanDTO();
        $dto->plannedAt = new \DateTimeImmutable('2024-06-01');
        // expectedAt в сущности — синоним plannedAt; null — берётся plannedAt.
        $dto->expectedAt = null;
        $dto->amount = '200.00';
        $dto->cashflowCategory = $this->createCategory($company, 'Другая');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        (new UpdatePaymentPlanAction($entityManager, new PaymentPlanDtoApplier(new PaymentPlanService())))($plan, $company, $dto);

        self::assertSame('200.00', $plan->getAmount());
        self::assertSame('2024-06-01', $plan->getPlannedAt()?->format('Y-m-d'));
        self::assertSame(PaymentPlanStatusEnum::PLANNED, $plan->getStatus());
    }

    public function testChangeStatusRejectsForbiddenTransitionWithoutFlush(): void
    {
        $company = $this->createCompany();
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $this->createCategory($company, 'Расходы'), new \DateTimeImmutable('2024-05-01'), '100.00');
        $plan->setStatus(PaymentPlanStatusEnum::PAID);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);

        (new ChangePaymentPlanStatusAction($entityManager, new PaymentPlanService()))($plan, PaymentPlanStatusEnum::PLANNED->value);
    }

    public function testPostponeMovesPlannedDateAndFlushes(): void
    {
        $company = $this->createCompany();
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $this->createCategory($company, 'Расходы'), new \DateTimeImmutable('2024-05-01'), '100.00');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        (new PostponePaymentPlanAction($entityManager))($plan, 7);

        self::assertSame('2024-05-08', $plan->getPlannedAt()?->format('Y-m-d'));
    }

    public function testChangeStatusAppliesAllowedTransitionAndFlushes(): void
    {
        $company = $this->createCompany();
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $this->createCategory($company, 'Расходы'), new \DateTimeImmutable('2024-05-01'), '100.00');
        $plan->setStatus(PaymentPlanStatusEnum::PLANNED);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        (new ChangePaymentPlanStatusAction($entityManager, new PaymentPlanService()))($plan, PaymentPlanStatusEnum::APPROVED->value);

        self::assertSame(PaymentPlanStatusEnum::APPROVED, $plan->getStatus());
    }

    public function testPostponeWithoutDateDoesNothing(): void
    {
        $company = $this->createCompany();
        $plan = new PaymentPlan(Uuid::uuid4()->toString(), $company, $this->createCategory($company, 'Расходы'), new \DateTimeImmutable('2024-05-01'), '100.00');
        // setExpectedAt() пишет в plannedAt — так у плана пропадает дата.
        $plan->setExpectedAt(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('flush');

        (new PostponePaymentPlanAction($entityManager))($plan, 7);

        self::assertNull($plan->getPlannedAt());
    }

    public function testCreateRejectsUnknownStatusWithoutWriting(): void
    {
        $company = $this->createCompany();
        $dto = new PaymentPlanDTO();
        $dto->plannedAt = new \DateTimeImmutable('2024-05-11');
        $dto->expectedAt = null;
        $dto->amount = '100.00';
        $dto->cashflowCategory = $this->createCategory($company, 'Расходы');
        $dto->status = 'unknown';
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $this->expectException(\ValueError::class);

        (new CreatePaymentPlanAction($entityManager, new PaymentPlanDtoApplier(new PaymentPlanService()), $this->createMock(PaymentPlanRepository::class), $this->createMock(ManagerRegistry::class)))($company, $dto);
    }

    private function createCategory(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);

        return $category;
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('user@example.com');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}
