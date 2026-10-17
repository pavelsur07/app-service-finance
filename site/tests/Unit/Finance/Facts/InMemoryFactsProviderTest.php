<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Facts;

use App\Company\Entity\Company;
use App\Finance\Facts\InMemoryFactsProvider;
use App\Finance\Report\PlReportPeriod;
use PHPUnit\Framework\TestCase;

final class InMemoryFactsProviderTest extends TestCase
{
    public function testLooksUpCodeCaseInsensitively(): void
    {
        $provider = new InMemoryFactsProvider(['rev_wb' => 10.0, 'REV_WB ' => 5.0, 'COGS' => -3.0]);
        $company = $this->createMock(Company::class);
        $period = PlReportPeriod::forMonth(new \DateTimeImmutable('2026-01-01'));

        self::assertSame(15.0, $provider->value($company, $period, 'Rev_Wb'));
        self::assertSame(-3.0, $provider->value($company, $period, 'cogs'));
        self::assertSame(0.0, $provider->value($company, $period, 'UNKNOWN'));
    }
}
