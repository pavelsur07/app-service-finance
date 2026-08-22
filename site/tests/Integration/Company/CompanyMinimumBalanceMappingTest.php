<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Company\Entity\Company;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class CompanyMinimumBalanceMappingTest extends IntegrationTestCase
{
    public function testMinimumBalanceUsesMigrationColumnNames(): void
    {
        $metadata = $this->em->getClassMetadata(Company::class);

        self::assertTrue($metadata->hasField('minimumBalance.amountMinor'));
        self::assertTrue($metadata->hasField('minimumBalance.currency'));
        self::assertSame('minimum_balance_amount_minor', $metadata->getColumnName('minimumBalance.amountMinor'));
        self::assertSame('minimum_balance_currency', $metadata->getColumnName('minimumBalance.currency'));
        self::assertSame('money_amount_minor', $metadata->getTypeOfField('minimumBalance.amountMinor'));
    }
}
