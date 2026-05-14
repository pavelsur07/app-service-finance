<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\CostCalculator;

use App\Marketplace\Infrastructure\Normalizer\Wildberries\WbSalesReportRowNormalizer;
use App\Marketplace\Service\CostCalculator\WbCommissionCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class WbCostExternalIdBuilderTest extends TestCase
{
    public function testSameSridDifferentRrdIdProduceDifferentExternalIds(): void
    {
        $calculator = new WbCommissionCalculator();

        $first = $calculator->calculate($this->saleItem(['srid' => 'same-srid', 'rrd_id' => '1001']), null);
        $second = $calculator->calculate($this->saleItem(['srid' => 'same-srid', 'rrd_id' => '1002']), null);

        self::assertSame('wb:1001:commission', $first[0]['external_id']);
        self::assertSame('wb:1002:commission', $second[0]['external_id']);
    }

    public function testRowWithoutRrdIdDoesNotCreateCostEntry(): void
    {
        $logger = new CollectingLogger();
        $calculator = new WbCommissionCalculator(new WbSalesReportRowNormalizer(), $logger);

        $entries = $calculator->calculate($this->saleItem(['rrd_id' => null, 'rrdId' => null]), null);

        self::assertSame([], $entries);
        self::assertCount(1, $logger->warnings);
    }

    public function testExternalIdFormatIsStrict(): void
    {
        $calculator = new WbCommissionCalculator();
        $entries = $calculator->calculate($this->saleItem(['rrdId' => '777']), null);

        self::assertSame('wb:777:commission', $entries[0]['external_id']);
    }

    private function saleItem(array $overrides = []): array
    {
        return array_merge([
            'doc_type_name' => 'Продажа',
            'srid' => 'SRID-1',
            'rrd_id' => '1001',
            'sale_dt' => '2026-01-15 10:00:00',
            'retail_price_withdisc_rub' => 1000.00,
            'acquiring_fee' => 20.00,
            'ppvz_for_pay' => 800.00,
        ], $overrides);
    }
}

final class CollectingLogger extends AbstractLogger
{
    public array $warnings = [];

    public function log($level, $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->warnings[] = ['message' => (string) $message, 'context' => $context];
        }
    }
}
