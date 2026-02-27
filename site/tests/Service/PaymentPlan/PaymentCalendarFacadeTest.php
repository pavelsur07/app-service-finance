<?php

namespace App\Tests\Service\PaymentPlan;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Service\PaymentPlan\PaymentCalendarFacade;
use App\Cash\Service\PaymentPlan\PaymentPlanService;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\DTO\PaymentPlanDTO;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PaymentCalendarFacadeTest extends TestCase
{
    public function testCreatePlanFromDtoMapsProbabilityAndFrozenWithExpectedAtFallback(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $paymentPlanService = $this->createMock(PaymentPlanService::class);

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

        $paymentPlanService
            ->expects(self::once())
            ->method('applyCompanyScope');
        $paymentPlanService
            ->expects(self::once())
            ->method('resolveTypeByCategory')
            ->with($category)
            ->willReturn(PaymentPlanTypeEnum::OUTFLOW->value);

        $entityManager
            ->expects(self::once())
            ->method('persist');
        $entityManager
            ->expects(self::once())
            ->method('flush');

        $facade = new PaymentCalendarFacade($entityManager, $paymentPlanService);
        $plan = $facade->createPlanFromDto($company, $dto);

        self::assertSame(50, $plan->getProbability());
        self::assertTrue($plan->isFrozen());
        self::assertSame('2024-05-11', $plan->getExpectedAt()?->format('Y-m-d'));
        self::assertSame(PaymentPlanStatusEnum::APPROVED, $plan->getStatus());
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
