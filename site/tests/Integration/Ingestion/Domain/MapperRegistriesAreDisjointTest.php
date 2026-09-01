<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Domain;

use App\Ingestion\Domain\Service\MapperRegistry;
use App\Ingestion\Domain\Service\OrderMapperRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MapperRegistriesAreDisjointTest extends IntegrationTestCase
{
    /**
     * Ветвление нормализации выбирает путь по наличию маппера заказов. Если бы
     * один и тот же (source, resourceType) был зарегистрирован в обоих
     * реестрах, ресурс молча ушёл бы в заказный путь, а финансовые данные
     * перестали бы появляться — без единой ошибки.
     */
    public function testOrderAndFinancialMappersNeverClaimTheSameResource(): void
    {
        /** @var OrderMapperRegistry $orderRegistry */
        $orderRegistry = self::getContainer()->get(OrderMapperRegistry::class);
        /** @var MapperRegistry $financialRegistry */
        $financialRegistry = self::getContainer()->get(MapperRegistry::class);

        $overlap = [];
        foreach ($orderRegistry->keys() as $key) {
            [$source, $resourceType] = explode(':', $key, 2);

            if ($financialRegistry->has(IngestSource::from($source), $resourceType)) {
                $overlap[] = $key;
            }
        }

        self::assertSame([], $overlap, 'Ресурс не может обслуживаться и заказным, и финансовым маппером.');
    }
}
