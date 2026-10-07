<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Service\CompanyOwnerAccountCreator;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class CompanyOwnerAccountCreatorBalanceSeedTest extends IntegrationTestCase
{
    public function testRegistrationSeedsBalanceStructureFromTemplate(): void
    {
        $user = UserBuilder::aUser()
            ->withId(Uuid::uuid7()->toString())
            ->withEmail(Uuid::uuid7()->toString().'@example.test')
            ->build();

        $company = self::getContainer()->get(CompanyOwnerAccountCreator::class)
            ->create($user, 'plain-password', 'Seed Co', false);

        $codes = $this->connection->fetchFirstColumn('SELECT code FROM balance_articles WHERE company_id=?', [(string) $company->getId()]);
        self::assertCount(33, $codes);
        foreach (['MONEY', 'CASH_BANK', 'CASH_PAYMENT_SYSTEMS', 'AR_MARKETPLACES', 'INV_MARKETPLACE', 'TAX_RECEIVABLE', 'EQUITY', 'LONG_LOANS'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND action='system_seed'", [(string) $company->getId()]));
    }
}
