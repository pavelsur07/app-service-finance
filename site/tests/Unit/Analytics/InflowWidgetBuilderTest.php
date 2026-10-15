<?php

declare(strict_types=1);

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Application\DTO\CashDailyAmount;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Support\Mock\MocksFinalClasses;
use PHPUnit\Framework\TestCase;

final class InflowWidgetBuilderTest extends TestCase
{
    use MocksFinalClasses;

    public function testBuildReturnsSumsSeriesAndDeltas(): void
    {
        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $period = new Period(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10'));

        $cashFacade = $this->createFinalMock(CashFacade::class);
        $cashFacade->expects(self::once())
            ->method('listActiveMoneyAccountIds')
            ->with('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12', 'USD')
            ->willReturn(['acc-1']);
        $cashFacade->expects(self::exactly(2))
            ->method('sumInflowExcludingTransfers')
            ->willReturnOnConsecutiveCalls('100.00', '40.00');
        $cashFacade->expects(self::once())
            ->method('sumInflowByDayExcludingTransfers')
            ->willReturn([
                new CashDailyAmount('2026-03-03', '30.00'),
                new CashDailyAmount('2026-03-04', '70.00'),
            ]);

        $builder = new InflowWidgetBuilder(self::asInstanceOf($cashFacade, CashFacade::class), new DrilldownBuilder());

        $result = $builder->build($company, $period, FiatCurrency::USD)->toArray();

        self::assertSame(100.0, $result['sum']);
        self::assertSame(60.0, $result['delta_abs']);
        self::assertSame(150.0, $result['delta_pct']);
        self::assertSame(10.0, $result['avg_daily']);
        self::assertCount(2, $result['series']);
        self::assertSame('2026-03-03', $result['series'][0]['date']);
        self::assertSame(30.0, $result['series'][0]['value']);
        self::assertSame('USD', $result['drilldown']['params']['currency']);
        self::assertSame(['acc-1'], $result['drilldown']['params']['account_ids']);
    }

    private function createCompany(string $companyId): Company
    {
        $company = $this->getMockBuilder(Company::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();

        $company->method('getId')->willReturn($companyId);

        return $company;
    }
}
