<?php

declare(strict_types=1);

namespace App\Tests\Integration\Budget;

use App\Budget\Application\ChangeBudgetVersionStatusAction;
use App\Budget\Application\DTO\ChangeBudgetVersionStatusCommand;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetApprovalForbiddenException;
use App\Budget\Exception\BudgetVersionNotFoundException;
use App\Budget\Exception\BudgetYearAlreadyApprovedException;
use App\Budget\Exception\InvalidBudgetVersionTransitionException;
use App\Tests\Builders\Budget\BudgetVersionBuilder;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class ChangeBudgetVersionStatusActionTest extends BudgetIntegrationTestCase
{
    private const VERSION_ID = '0193cccc-0000-7000-8000-0000000f2001';
    private const OTHER_VERSION_ID = '0193cccc-0000-7000-8000-0000000f2002';

    public function testOwnerApprovesDraftAndRepeatIsNoOp(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT);

        $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::OWNER_ID);
        $this->em->clear();
        $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::OWNER_ID);

        self::assertSame('approved', $this->versionStatus(self::VERSION_ID));
    }

    public function testMemberCannotApprove(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT);

        $this->expectException(BudgetApprovalForbiddenException::class);
        try {
            $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::MEMBER_ID);
        } finally {
            self::assertSame('draft', $this->versionStatus(self::VERSION_ID));
        }
    }

    public function testMemberRepeatingApprovalIsStillForbidden(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::APPROVED);

        $this->expectException(BudgetApprovalForbiddenException::class);
        $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::MEMBER_ID);
    }

    public function testSecondApprovedVersionOfYearIsRejected(): void
    {
        $this->given(self::OTHER_VERSION_ID, BudgetVersionStatus::APPROVED, name: 'Базовый');
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT, name: 'Оптимистичный');

        try {
            $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::OWNER_ID);
            self::fail('second approved version must be rejected');
        } catch (BudgetYearAlreadyApprovedException $exception) {
            self::assertStringContainsString('Базовый', $exception->getMessage());
        }
        self::assertSame('draft', $this->versionStatus(self::VERSION_ID));
    }

    public function testApprovedVersionsOfDifferentYearsCoexist(): void
    {
        $this->given(self::OTHER_VERSION_ID, BudgetVersionStatus::APPROVED, year: 2026);
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT, year: 2027);

        $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::OWNER_ID);

        self::assertSame('approved', $this->versionStatus(self::VERSION_ID));
    }

    public function testDatabaseRejectsSecondApprovedVersionOfYear(): void
    {
        $this->given(self::OTHER_VERSION_ID, BudgetVersionStatus::APPROVED);
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->connection->executeStatement("UPDATE budget_versions SET status = 'approved' WHERE id = ?", [self::VERSION_ID]);
    }

    public function testOnlyOwnerReturnsApprovedVersionToDraft(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::APPROVED);

        try {
            $this->change(self::VERSION_ID, BudgetVersionStatus::DRAFT, self::MEMBER_ID);
            self::fail('member must not return approved version to draft');
        } catch (BudgetApprovalForbiddenException) {
        }
        self::assertSame('approved', $this->versionStatus(self::VERSION_ID));

        $this->change(self::VERSION_ID, BudgetVersionStatus::DRAFT, self::OWNER_ID);
        self::assertSame('draft', $this->versionStatus(self::VERSION_ID));
    }

    public function testMemberArchivesAndRestoresDraft(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT);

        $this->change(self::VERSION_ID, BudgetVersionStatus::ARCHIVED, self::MEMBER_ID);
        self::assertSame('archived', $this->versionStatus(self::VERSION_ID));

        $this->em->clear();
        $this->change(self::VERSION_ID, BudgetVersionStatus::DRAFT, self::MEMBER_ID);
        self::assertSame('draft', $this->versionStatus(self::VERSION_ID));
    }

    public function testApprovedVersionCannotBeArchived(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::APPROVED);

        $this->expectException(InvalidBudgetVersionTransitionException::class);
        $this->change(self::VERSION_ID, BudgetVersionStatus::ARCHIVED, self::OWNER_ID);
    }

    public function testForeignVersionIsNotFound(): void
    {
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId(self::VERSION_ID)->forCompany(self::OTHER_COMPANY_ID)->build());
        $this->em->flush();

        $this->expectException(BudgetVersionNotFoundException::class);
        $this->change(self::VERSION_ID, BudgetVersionStatus::ARCHIVED, self::OWNER_ID);
    }

    public function testWaitsForConcurrentChangeOfSameVersion(): void
    {
        $this->given(self::VERSION_ID, BudgetVersionStatus::DRAFT);

        $worker = DriverManager::getConnection($this->connection->getParams());
        try {
            $worker->beginTransaction();
            $worker->executeStatement('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => 'budget_version:'.self::VERSION_ID]);
            $this->connection->executeStatement("SET lock_timeout = '300ms'");

            try {
                $this->change(self::VERSION_ID, BudgetVersionStatus::APPROVED, self::OWNER_ID);
                self::fail('status change must wait for the version lock');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
            }
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $worker->rollBack();
            $worker->close();
        }
    }

    private function given(string $id, BudgetVersionStatus $status, int $year = 2027, string $name = 'Вариант'): void
    {
        $this->em->persist(BudgetVersionBuilder::aVersion()->withId($id)->forCompany(self::COMPANY_ID)->withYear($year)->withName($name)->withStatus($status)->build());
        $this->em->flush();
        $this->em->clear();
    }

    private function change(string $versionId, BudgetVersionStatus $target, string $actorUserId): void
    {
        self::getContainer()->get(ChangeBudgetVersionStatusAction::class)(new ChangeBudgetVersionStatusCommand(self::COMPANY_ID, $versionId, $target, $actorUserId));
    }

    private function versionStatus(string $versionId): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM budget_versions WHERE id = ?', [$versionId]);
    }
}
