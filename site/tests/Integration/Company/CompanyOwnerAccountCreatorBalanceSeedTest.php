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

        $codes = $this->connection->fetchFirstColumn('SELECT code FROM balance_articles WHERE company_id=? ORDER BY code', [(string) $company->getId()]);
        self::assertSame([
            'CASH', 'CONTRIBUTED_CAPITAL', 'CURRENT_ASSETS', 'CURRENT_LIABILITIES', 'EQUITY',
            'LIABILITIES', 'LONG_LIABILITIES', 'NON_CURRENT_ASSETS', 'RETAINED_RESULT',
        ], $codes);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM balance_audit_events WHERE company_id=? AND action='system_seed'", [(string) $company->getId()]));
    }
}
