<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Application\Service;

use App\Cash\Application\Service\CashTransactionAutoRuleProvenanceResolver;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Shared\Repository\AuditLogRepository;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

final class CashTransactionAutoRuleProvenanceResolverTest extends TestCase
{
    public function testRecognizesLatestMatchingAutoRuleAssignment(): void
    {
        [$transaction, $categoryId] = $this->createClassifiedTransaction();
        $audit = $this->autoRuleAudit($transaction, $categoryId, new \DateTimeImmutable('2026-07-22 10:00:00'));
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->expects(self::once())->method('findBy')->with([
            'companyId' => (string) $transaction->getCompany()->getId(),
            'entityClass' => CashTransaction::class,
            'entityId' => (string) $transaction->getId(),
        ], ['createdAt' => 'DESC'], 50)->willReturn([$audit]);

        $provenance = (new CashTransactionAutoRuleProvenanceResolver($repository, new NullLogger()))->resolve($transaction);

        self::assertTrue($provenance->isAutoAssigned('cashflowCategory'));
        self::assertFalse($provenance->isAutoAssigned('projectDirection'));
    }

    public function testProtectsFieldAfterManualChange(): void
    {
        [$transaction, $categoryId] = $this->createClassifiedTransaction();
        $manualAudit = new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            ['cashflowCategory' => ['old-category', $categoryId]],
            Uuid::uuid4()->toString(),
            new \DateTimeImmutable('2026-07-22 10:01:00'),
        );
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('findBy')->willReturn([
            $manualAudit,
            $this->autoRuleAudit($transaction, 'old-category', new \DateTimeImmutable('2026-07-22 10:00:00')),
        ]);

        $provenance = (new CashTransactionAutoRuleProvenanceResolver($repository, new NullLogger()))->resolve($transaction);

        self::assertFalse($provenance->isAutoAssigned('cashflowCategory'));
    }

    public function testProtectsFieldWhenCurrentValueDiffersFromAudit(): void
    {
        [$transaction] = $this->createClassifiedTransaction();
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('findBy')->willReturn([
            $this->autoRuleAudit($transaction, Uuid::uuid4()->toString(), new \DateTimeImmutable('2026-07-22 10:00:00')),
        ]);

        $provenance = (new CashTransactionAutoRuleProvenanceResolver($repository, new NullLogger()))->resolve($transaction);

        self::assertFalse($provenance->isAutoAssigned('cashflowCategory'));
    }

    public function testProtectsFieldWhenSameSecondContainsManualAudit(): void
    {
        [$transaction, $categoryId] = $this->createClassifiedTransaction();
        $timestamp = new \DateTimeImmutable('2026-07-22 10:00:00');
        $manualAudit = new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            ['cashflowCategory' => ['old-category', $categoryId]],
            Uuid::uuid4()->toString(),
            $timestamp,
        );
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('findBy')->willReturn([
            $manualAudit,
            $this->autoRuleAudit($transaction, $categoryId, $timestamp),
        ]);

        $provenance = (new CashTransactionAutoRuleProvenanceResolver($repository, new NullLogger()))->resolve($transaction);

        self::assertFalse($provenance->isAutoAssigned('cashflowCategory'));
    }

    public function testProtectsFieldsWhenAuditHistoryIsTruncated(): void
    {
        [$transaction, $categoryId] = $this->createClassifiedTransaction();
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('findBy')->willReturn(array_fill(
            0,
            50,
            $this->autoRuleAudit($transaction, $categoryId, new \DateTimeImmutable('2026-07-22 10:00:00')),
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Auto-rule provenance unresolved because audit history was truncated.',
            [
                'companyId' => (string) $transaction->getCompany()->getId(),
                'transactionId' => (string) $transaction->getId(),
            ],
        );

        $provenance = (new CashTransactionAutoRuleProvenanceResolver($repository, $logger))->resolve($transaction);

        self::assertFalse($provenance->isAutoAssigned('cashflowCategory'));
    }

    public function testResolvesProjectAndResponsibilityCenterIndependently(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $project = new ProjectDirection(Uuid::uuid4()->toString(), $company, 'Project');
        $center = new FinancialResponsibilityCenter((string) $company->getId(), 'CFO_PROJECT', 'Project CFO');
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->build()
            ->setProjectDirection($project)
            ->setResponsibilityCenterId($center->getId());
        $audit = new AuditLog(
            (string) $company->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            [
                'autoRules' => [
                    'projectDirection' => ['id' => Uuid::uuid4()->toString(), 'revision' => 1],
                    'responsibilityCenterId' => ['id' => Uuid::uuid4()->toString(), 'revision' => 1],
                ],
                'changes' => [
                    'projectDirection' => ['before' => null, 'after' => $project->getId()],
                    'responsibilityCenterId' => ['before' => null, 'after' => $center->getId()],
                ],
            ],
        );
        $repository = $this->createMock(AuditLogRepository::class);
        $repository->method('findBy')->willReturn([$audit]);
        $resolver = new CashTransactionAutoRuleProvenanceResolver($repository, new NullLogger());

        $matching = $resolver->resolve($transaction);
        $transaction->setResponsibilityCenterId(Uuid::uuid4()->toString());
        $partiallyChanged = $resolver->resolve($transaction);

        self::assertTrue($matching->isAutoAssigned('projectDirection'));
        self::assertTrue($matching->isAutoAssigned('responsibilityCenterId'));
        self::assertTrue($partiallyChanged->isAutoAssigned('projectDirection'));
        self::assertFalse($partiallyChanged->isAutoAssigned('responsibilityCenterId'));
    }

    /** @return array{CashTransaction, string} */
    private function createClassifiedTransaction(): array
    {
        $company = CompanyBuilder::aCompany()->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId(Uuid::uuid4()->toString())
            ->withCompany($company)
            ->build();
        $transaction = CashTransactionBuilder::aCashTransaction()
            ->forCompany($company)
            ->withCashflowCategory($category)
            ->build();

        return [$transaction, (string) $category->getId()];
    }

    private function autoRuleAudit(
        CashTransaction $transaction,
        string $categoryId,
        \DateTimeImmutable $createdAt,
    ): AuditLog {
        return new AuditLog(
            (string) $transaction->getCompany()->getId(),
            CashTransaction::class,
            (string) $transaction->getId(),
            AuditLogAction::UPDATE,
            [
                'autoRules' => [
                    'cashflowCategory' => ['id' => Uuid::uuid4()->toString(), 'revision' => 1],
                ],
                'changes' => [
                    'cashflowCategory' => ['before' => null, 'after' => $categoryId],
                ],
            ],
            null,
            $createdAt,
        );
    }
}
