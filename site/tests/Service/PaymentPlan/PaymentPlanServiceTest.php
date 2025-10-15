<?php

namespace App\Tests\Service\PaymentPlan;

use App\Domain\PaymentPlan\PaymentPlanStatus as PaymentPlanStatusValue;
use App\Domain\PaymentPlan\PaymentPlanType as PaymentPlanTypeValue;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\PaymentPlan;
use App\Entity\User;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Service\PaymentPlan\PaymentPlanService;
use DomainException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class PaymentPlanServiceTest extends TestCase
{
    public function testResolveTypeByCategoryForInflow(): void
    {
        $service = new PaymentPlanService();
        $company = $this->createCompany();

        $root = $this->createCategory($company, 'Доходы от продаж');
        $child = $this->createCategory($company, 'Поступления с маркетплейсов', $root);

        self::assertSame(PaymentPlanTypeValue::INFLOW, $service->resolveTypeByCategory($child));
    }

    public function testResolveTypeByCategoryForTransfer(): void
    {
        $service = new PaymentPlanService();
        $company = $this->createCompany();

        $root = $this->createCategory($company, 'Технические операции');
        $child = $this->createCategory($company, 'Перевод между счетами', $root);

        self::assertSame(PaymentPlanTypeValue::TRANSFER, $service->resolveTypeByCategory($child));
    }

    public function testResolveTypeByCategoryDefaultsToOutflow(): void
    {
        $service = new PaymentPlanService();
        $company = $this->createCompany();

        $root = $this->createCategory($company, 'Операционные расходы');
        $child = $this->createCategory($company, 'Аренда офиса', $root);

        self::assertSame(PaymentPlanTypeValue::OUTFLOW, $service->resolveTypeByCategory($child));
    }

    public function testTransitionStatusFollowsHappyPath(): void
    {
        $service = new PaymentPlanService();
        $plan = $this->createPlan();

        $service->transitionStatus($plan, PaymentPlanStatusValue::PLANNED);
        self::assertSame(PaymentPlanStatusEnum::PLANNED, $plan->getStatus());

        $service->transitionStatus($plan, PaymentPlanStatusValue::APPROVED);
        self::assertSame(PaymentPlanStatusEnum::APPROVED, $plan->getStatus());

        $service->transitionStatus($plan, PaymentPlanStatusValue::PAID);
        self::assertSame(PaymentPlanStatusEnum::PAID, $plan->getStatus());
    }

    public function testTransitionStatusRejectsInvalidJump(): void
    {
        $service = new PaymentPlanService();
        $plan = $this->createPlan();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition payment plan status');
        $service->transitionStatus($plan, PaymentPlanStatusValue::APPROVED);
    }

    public function testTransitionStatusRejectsAfterPaid(): void
    {
        $service = new PaymentPlanService();
        $plan = $this->createPlan();
        $plan->setStatus(PaymentPlanStatusEnum::PAID);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('terminal status');
        $service->transitionStatus($plan, PaymentPlanStatusValue::CANCELED);
    }

    public function testApplyCompanyScopeSetsCompanyWhenMissing(): void
    {
        $service = new PaymentPlanService();
        $plan = $this->createPlan();
        $company = $plan->getCompany();

        $clear = \Closure::bind(static function (PaymentPlan $plan): void {
            $plan->company = null;
        }, null, PaymentPlan::class);
        $clear($plan);

        $service->applyCompanyScope($plan, $company);

        self::assertSame($company, $plan->getCompany());
    }

    public function testApplyCompanyScopeRejectsForeignCompany(): void
    {
        $service = new PaymentPlanService();
        $plan = $this->createPlan();

        $foreignCompany = $this->createCompany();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('different company');
        $service->applyCompanyScope($plan, $foreignCompany);
    }

    private function createPlan(): PaymentPlan
    {
        $company = $this->createCompany();
        $category = $this->createCategory($company, 'Расходы');

        return new PaymentPlan(
            Uuid::uuid4()->toString(),
            $company,
            $category,
            new \DateTimeImmutable('2024-01-01'),
            '1000.00'
        );
    }

    private function createCategory(Company $company, string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        if (null !== $parent) {
            $category->setParent($parent);
        }

        return $category;
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('user@example.com');

        return new Company(Uuid::uuid4()->toString(), $user);
    }
}
