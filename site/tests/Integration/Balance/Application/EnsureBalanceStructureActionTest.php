<?php

declare(strict_types=1);

namespace App\Tests\Integration\Balance\Application;

use App\Balance\Application\EnsureBalanceStructureAction;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class EnsureBalanceStructureActionTest extends IntegrationTestCase
{
    public function testCreatesStructureOnceForCompanyWithoutIt(): void
    {
        $companyId = $this->createCompany();
        $ensure = self::getContainer()->get(EnsureBalanceStructureAction::class);

        self::assertTrue($ensure($companyId));
        self::assertSame(33, $this->articleCount($companyId));
        self::assertFalse($ensure($companyId));
        self::assertSame(33, $this->articleCount($companyId));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND action='system_seed'", [$companyId]));
    }

    public function testDoesNotTouchCompanyWithInitializedBook(): void
    {
        $companyId = $this->createCompany();
        $this->connection->executeStatement("INSERT INTO balance_books (id,company_id,currency,start_date,initialized,version,next_document_number,next_posting_sequence,created_at,updated_at) VALUES (?,?,'RUB','2025-01-01',true,0,1,1,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)", [Uuid::uuid7()->toString(), $companyId]);

        self::assertFalse(self::getContainer()->get(EnsureBalanceStructureAction::class)($companyId));
        self::assertSame(0, $this->articleCount($companyId));
    }

    private function createCompany(): string
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return (string) $company->getId();
    }

    private function articleCount(string $companyId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id=?', [$companyId]);
    }
}
