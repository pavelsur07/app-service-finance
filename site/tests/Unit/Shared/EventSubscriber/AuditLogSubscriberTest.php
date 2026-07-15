<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\EventSubscriber;

use App\Cash\Application\DTO\CashTransactionAutoRuleApplicationPlan;
use App\Cash\Application\Service\AutoRuleDispatchGuard;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\EventSubscriber\AuditLogSubscriber;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AuditLogSubscriberTest extends TestCase
{
    public function testDoesNotDuplicateExplicitAutoRuleApplicationAudit(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->build();
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
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $guard = new AutoRuleDispatchGuard();
        $subscriber = new AuditLogSubscriber(
            $this->createMock(AuditContextProvider::class),
            $entityManager,
            $guard,
        );

        $guard->suppress(
            static fn () => $subscriber->postUpdate(new PostUpdateEventArgs($transaction, $entityManager)),
            $plan,
        );
    }
}
