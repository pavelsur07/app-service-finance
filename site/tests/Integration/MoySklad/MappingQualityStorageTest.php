<?php

declare(strict_types=1);

namespace App\Tests\Integration\MoySklad;

use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MoySklad\Infrastructure\Query\MappingSettingsQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;
use App\Tests\Support\MoySklad\MappingFixtures;
use Ramsey\Uuid\Uuid;

final class MappingQualityStorageTest extends IntegrationTestCase
{
    use MappingFixtures;

    public function testFacadeReturnsAllBarcodeStringsOnlyForRequestedCompanyListings(): void
    {
        $data = $this->mappingCatalog($this->em);
        foreach (['0012345678901', '000ABC'] as $barcode) {
            $this->em->persist(new MarketplaceListingBarcode(Uuid::uuid7()->toString(), $data['listing'], $data['connection']->getCompanyId(), 'ozon', $barcode));
        }
        $this->em->flush();
        $facade = self::getContainer()->get(MarketplaceFacade::class);
        $id = $data['listing']->getId();
        self::assertSame([$id => ['000ABC', '0012345678901']], $facade->getListingBarcodesByIds($data['connection']->getCompanyId(), [$id]));
        self::assertSame([], $facade->getListingBarcodesByIds('99999999-9999-4999-8999-999999999999', [$id]));
        self::assertSame([], $facade->getListingBarcodesByIds($data['connection']->getCompanyId(), []));
    }

    public function testSettingsDefaultOffAndAreScopedByConnectionCompanyAndMarketplace(): void
    {
        $data = $this->mappingCatalog($this->em);
        $company = $data['connection']->getCompanyId();
        $connection = $data['connection']->getId();
        $query = new MappingSettingsQuery($this->connection);
        self::assertFalse($query->get($company, $connection, 'wildberries')->wbTrailingW);
        $this->connection->executeStatement('INSERT INTO moysklad_mapping_settings (company_id, connection_id, marketplace, wb_trailing_w, version) VALUES (?, ?, ?, TRUE, 1)', [$company, $connection, 'wildberries']);
        self::assertTrue($query->get($company, $connection, 'wildberries')->wbTrailingW);
        self::assertFalse($query->get($company, $connection, 'ozon')->wbTrailingW);
        self::assertFalse($query->get('99999999-9999-4999-8999-999999999999', $connection, 'wildberries')->wbTrailingW);
        self::assertFalse($query->get($company, Uuid::uuid7()->toString(), 'wildberries')->wbTrailingW);
    }
}
