<?php

namespace App\Tests\Unit\Analytics;

use App\Analytics\Application\DrilldownBuilder;
use App\Analytics\Application\Widget\InflowWidgetBuilder;
use App\Analytics\Domain\Period;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Entity\Company;
use PHPUnit\Framework\TestCase;

final class InflowWidgetBuilderTest extends TestCase
{
    public function testBuildReturnsSumsSeriesAndDeltas(): void
    {
        $company = $this->createCompany('76f4b0c3-6fd3-41bb-b426-0ea2fd21ae12');
        $period = new Period(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10'));

        $account = $this->createMock(MoneyAccount::class);
        $account->method('getId')->willReturn('acc-1');

        $accountRepository = $this->createMock(MoneyAccountRepository::class);
        $accountRepository->method('findByFilters')->willReturn([$account]);

        $transactionRepository = $this->createMock(CashTransactionRepository::class);
        $transactionRepository->expects(self::exactly(2))
            ->method('sumInflowByCompanyAndPeriodExcludeTransfers')
            ->willReturnOnConsecutiveCalls('100.00', '40.00');
        $transactionRepository->expects(self::once())
            ->method('sumInflowByDayExcludeTransfers')
            ->willReturn([
                ['date' => '2026-03-03', 'value' => '30.00'],
                ['date' => '2026-03-04', 'value' => '70.00'],
            ]);

        $builder = new InflowWidgetBuilder($accountRepository, $transactionRepository, new DrilldownBuilder());

        $result = $builder->build($company, $period)->toArray();

        self::assertSame(100.0, $result['sum']);
        self::assertSame(60.0, $result['delta_abs']);
        self::assertSame(150.0, $result['delta_pct']);
        self::assertSame(10.0, $result['avg_daily']);
        self::assertCount(2, $result['series']);
        self::assertSame('2026-03-03', $result['series'][0]['date']);
        self::assertSame(30.0, $result['series'][0]['value']);
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
