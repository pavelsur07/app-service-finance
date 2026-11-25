<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Application\CreateCompanyOwnerAccountAction;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CreateCompanyOwnerAccountActionBalanceSeedTest extends IntegrationTestCase
{
    public function testRegistrationSeedsBalanceStructureFromTemplate(): void
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid7()->toString())
            ->withEmail(Uuid::uuid7()->toString().'@example.test')
            ->build();

        $company = self::getContainer()->get(CreateCompanyOwnerAccountAction::class)
            ->__invoke($user, 'plain-password', 'Seed Co', false);

        $codes = $this->connection->fetchFirstColumn('SELECT code FROM balance_articles WHERE company_id=?', [(string) $company->getId()]);
        self::assertCount(34, $codes);
        foreach (['MONEY', 'CASH_BANK', 'CASH_PAYMENT_SYSTEMS', 'AR_MARKETPLACES', 'INV_MARKETPLACE', 'TAX_RECEIVABLE', 'EQUITY', 'LONG_LOANS'] as $code) {
            self::assertContains($code, $codes);
        }
        // Аудит сида называет шаблон (профиль) и его версию.
        $changes = $this->connection->fetchFirstColumn("SELECT changes::text FROM balance_audit_events WHERE company_id=? AND object_type='book' AND action='system_seed'", [(string) $company->getId()]);
        self::assertCount(1, $changes);
        self::assertEquals(['template' => 'MARKETPLACE', 'version' => 4], json_decode((string) $changes[0], true, 512, \JSON_THROW_ON_ERROR));
        // Типовой справочник счетов — с записью аудита на каждый.
        self::assertCount(28, $this->connection->fetchFirstColumn('SELECT code FROM balance_accounts WHERE company_id=? ORDER BY code', [(string) $company->getId()]));
        self::assertSame(28, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND object_type='account' AND action='system_seed'", [(string) $company->getId()]));
    }
}
