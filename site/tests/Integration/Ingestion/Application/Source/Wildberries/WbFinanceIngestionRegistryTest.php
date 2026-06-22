<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Wildberries;

use App\Ingestion\Application\Source\Wildberries\WbFinanceSalesReportDetailedMapper;
use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\Domain\Service\MapperRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class WbFinanceIngestionRegistryTest extends IntegrationTestCase
{
    public function testWildberriesFinanceMapperIsRegistered(): void
    {
        /** @var MapperRegistry $mapperRegistry */
        $mapperRegistry = self::getContainer()->get(MapperRegistry::class);

        self::assertInstanceOf(
            WbFinanceSalesReportDetailedMapper::class,
            $mapperRegistry->get(IngestSource::WILDBERRIES, WbResourceType::FINANCE_SALES_REPORT_DETAILED),
        );
    }
}
