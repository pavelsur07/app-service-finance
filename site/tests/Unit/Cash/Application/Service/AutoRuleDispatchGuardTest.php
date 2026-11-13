<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\Service\AutoRule\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AutoRuleDispatchGuardTest extends TestCase
{
    public function testRestoresStateAfterFailure(): void
    {
        $guard = new AutoRuleDispatchGuard();

        try {
            $guard->suppress(static function () use ($guard): void {
                self::assertTrue($guard->isSuppressed());

                throw new \RuntimeException('test');
            });
            self::fail('Exception was expected.');
        } catch (\RuntimeException) {
            self::assertFalse($guard->isSuppressed());
        }
    }

    public function testExposesApplicationPlanOnlyInsideSuppressedOperation(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Rule',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->build(),
        );
        $plan = new CashTransactionAutoRuleApplicationPlan(
            $rule,
            ['cashflowCategory' => ['before' => null, 'after' => $rule->getCashflowCategory()?->getId()]],
            $rule->getCashflowCategory(),
            null,
            null,
        );
        $guard = new AutoRuleDispatchGuard();

        $guard->suppress(static function () use ($guard, $plan): void {
            self::assertSame($plan, $guard->getApplicationPlan());
        }, $plan);

        self::assertNull($guard->getApplicationPlan());
    }
}
