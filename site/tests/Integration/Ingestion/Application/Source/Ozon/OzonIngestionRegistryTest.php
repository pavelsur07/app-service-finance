<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Ingestion\Application\Source\Ozon\OzonAccrualByDayMapper;
use App\Ingestion\Application\Source\Ozon\OzonAccrualShadowMapper;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\Application\Source\Ozon\OzonSellerReportConnector;
use App\Ingestion\Domain\Service\ConnectorRegistry;
use App\Ingestion\Domain\Service\MapperRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Exception\MapperNotFoundException;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonIngestionRegistryTest extends IntegrationTestCase
{
    public function testOzonConnectorAndMappersAreRegistered(): void
    {
        /** @var ConnectorRegistry $connectorRegistry */
        $connectorRegistry = self::getContainer()->get(ConnectorRegistry::class);
        /** @var MapperRegistry $mapperRegistry */
        $mapperRegistry = self::getContainer()->get(MapperRegistry::class);

        self::assertInstanceOf(OzonSellerReportConnector::class, $connectorRegistry->get(IngestSource::OZON));
        self::assertInstanceOf(
            OzonAccrualShadowMapper::class,
            $mapperRegistry->get(IngestSource::OZON, OzonResourceType::ACCRUAL_POSTINGS),
        );
        self::assertInstanceOf(
            OzonAccrualByDayMapper::class,
            $mapperRegistry->get(IngestSource::OZON, OzonResourceType::ACCRUAL_BY_DAY),
        );
        self::assertInstanceOf(
            OzonAccrualShadowMapper::class,
            $mapperRegistry->get(IngestSource::OZON, OzonResourceType::ACCRUAL_TYPES),
        );

        $this->expectException(MapperNotFoundException::class);
        $mapperRegistry->get(IngestSource::OZON, 'ozon_seller_daily_report');
    }
}
