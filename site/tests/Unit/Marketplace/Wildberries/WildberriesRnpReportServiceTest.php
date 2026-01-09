<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Wildberries;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesRnpDailyRepository;
use App\Marketplace\Wildberries\Service\WildberriesRnpReportService;
use PHPUnit\Framework\TestCase;

final class WildberriesRnpReportServiceTest extends TestCase
{
    public function testBuildReportCalculatesAggregates(): void
    {
        $company = $this->createStub(Company::class);
        $company->method('getId')->willReturn('11111111-1111-1111-1111-111111111111');

        $from = new \DateTimeImmutable('2024-01-01');
        $to = new \DateTimeImmutable('2024-01-02');

        $repository = $this->createMock(WildberriesRnpDailyRepository::class);
        $repository
            ->expects(self::once())
            ->method('findRangeGroupedDaySku')
            ->with($company, $from, $to, ['sku' => [], 'brand' => [], 'category' => []])
            ->willReturn([
                [
                    'date' => new \DateTimeImmutable('2024-01-01'),
                    'sku' => 'SKU-1',
                    'category' => 'Cat-A',
                    'brand' => 'Brand-A',
                    'orders_count_spp' => '5',
                    'orders_sum_spp_minor' => '1500',
                    'sales_count_spp' => '4',
                    'sales_sum_spp_minor' => '1200',
                    'ad_cost_sum_minor' => '100',
                    'buyout_rate' => '75.00',
                    'cogs_sum_spp_minor' => '800',
                ],
                [
                    'date' => new \DateTimeImmutable('2024-01-02'),
                    'sku' => 'SKU-2',
                    'category' => null,
                    'brand' => 'Brand-B',
                    'orders_count_spp' => '3',
                    'orders_sum_spp_minor' => '900',
                    'sales_count_spp' => '3',
                    'sales_sum_spp_minor' => '900',
                    'ad_cost_sum_minor' => '150',
                    'buyout_rate' => '50.00',
                    'cogs_sum_spp_minor' => '400',
                ],
            ]);

        $repository
            ->expects(self::once())
            ->method('sumRangeByCompany')
            ->with($company, $from, $to, ['sku' => [], 'brand' => [], 'category' => []])
            ->willReturn([
                'orders_count_spp_total' => 8,
                'orders_sum_spp_minor_total' => 2400,
                'sales_count_spp_total' => 7,
                'sales_sum_spp_minor_total' => 2100,
                'ad_cost_sum_minor_total' => 250,
                'cogs_sum_spp_minor_total' => 1200,
                'buyout_rate_weighted' => '62.5',
            ]);

        $service = new WildberriesRnpReportService($repository);
        $report = $service->buildReport($company, $from, $to);

        self::assertSame('11111111-1111-1111-1111-111111111111', $report['meta']['companyId']);
        self::assertSame('2024-01-01', $report['meta']['from']);
        self::assertSame('2024-01-02', $report['meta']['to']);
        self::assertSame('RUB', $report['meta']['currency']);
        self::assertSame(['sku' => [], 'brand' => [], 'category' => []], $report['meta']['filters']);

        self::assertCount(2, $report['items']);
        self::assertSame(400, $report['items'][0]['gross_profit_minor']);
        self::assertSame(300, $report['items'][0]['contribution_minor']);
        self::assertSame('75.00', $report['items'][0]['buyout_rate']);
        self::assertSame('50.00', $report['items'][1]['buyout_rate']);

        self::assertSame(500, $report['items'][1]['gross_profit_minor']);
        self::assertSame(350, $report['items'][1]['contribution_minor']);

        self::assertSame(8, $report['totals']['orders_count_spp']);
        self::assertSame(2400, $report['totals']['orders_sum_spp_minor']);
        self::assertSame(7, $report['totals']['sales_count_spp']);
        self::assertSame(2100, $report['totals']['sales_sum_spp_minor']);
        self::assertSame(250, $report['totals']['ad_cost_sum_minor']);
        self::assertSame(1200, $report['totals']['cogs_sum_spp_minor']);
        self::assertSame(900, $report['totals']['gross_profit_minor']);
        self::assertSame(650, $report['totals']['contribution_minor']);
        self::assertSame('62.50', $report['totals']['buyout_rate_weighted']);
    }

    public function testResolvePeriodWeek(): void
    {
        $repository = $this->createMock(WildberriesRnpDailyRepository::class);
        $service = new WildberriesRnpReportService($repository);

        $anchor = new \DateTimeImmutable('2024-07-17'); // Wednesday
        ['from' => $from, 'to' => $to] = $service->resolvePeriod('week', $anchor);

        self::assertSame('2024-07-15', $from->format('Y-m-d'));
        self::assertSame('2024-07-21', $to->format('Y-m-d'));
    }
}
