<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Application;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Inventory\Application\NormalizeInventorySnapshotAction;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Entity\Location;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Inventory\Infrastructure\Query\InventoryStockReportQuery;
use App\Inventory\Infrastructure\Query\StockQtyByListingOnDateQuery;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
use App\Tests\Builders\Inventory\LocationBuilder;
use App\Tests\Builders\Inventory\StockSnapshotBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class NormalizeInventorySnapshotActionTest extends IntegrationTestCase
{
    public function testIdempotentAndMappingScenarios(): void
    {
        $company = $this->createCompany(901);
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::OZON, SnapshotTriggerType::Manual);
        $session->markCompleted();
        $this->em->persist($session);

        $this->em->persist($this->raw($company->getId(), $session->getId(), 'SKU-U', 10, 2, 'fbo', 'OF-U'));
        $this->em->persist($this->raw($company->getId(), $session->getId(), 'SKU-M', 20, 3, 'fbs', 'OF-M'));
        $this->em->persist($this->raw($company->getId(), $session->getId(), 'SKU-A', 30, 4, 'fbo', 'OF-A'));
        $this->em->persist($this->raw($company->getId(), $session->getId(), 'SKU-O', 40, 5, 'fbs', 'OF-O'));

        $product = new Product('30000000-0000-4000-8000-000000000901', $company);
        $product->setSku('PRD-901')->setName('P901');
        $this->em->persist($product);

        $mapped = new MarketplaceListing('50000000-0000-4000-8000-000000000901', $company, $product, MarketplaceType::OZON);
        $mapped->setMarketplaceSku('SKU-M')->setPrice('100.00');
        $orphan = new MarketplaceListing('50000000-0000-4000-8000-000000000902', $company, null, MarketplaceType::OZON);
        $orphan->setMarketplaceSku('SKU-O')->setPrice('100.00');
        $amb1 = new MarketplaceListing('50000000-0000-4000-8000-000000000903', $company, null, MarketplaceType::OZON);
        $amb1->setMarketplaceSku('SKU-A')->setSize('L')->setPrice('100.00');
        $amb2 = new MarketplaceListing('50000000-0000-4000-8000-000000000904', $company, null, MarketplaceType::OZON);
        $amb2->setMarketplaceSku('SKU-A')->setSize('XL')->setPrice('100.00');
        $this->em->persist($mapped);
        $this->em->persist($orphan);
        $this->em->persist($amb1);
        $this->em->persist($amb2);
        $this->em->flush();

        $action = self::getContainer()->get(NormalizeInventorySnapshotAction::class);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);

        $rows = $this->em->getRepository(StockSnapshot::class)->findBy(['companyId' => $company->getId()]);
        self::assertCount(4, $rows);
        self::assertSame(2, $this->em->getRepository(Location::class)->count(['companyId' => $company->getId(), 'externalSystem' => MarketplaceType::OZON]));

        $bySku = [];
        foreach ($rows as $row) {
            $bySku[$row->getSourceSku()] = $row;
        }

        self::assertSame(StockSnapshotMappingStatus::Unmapped, $bySku['SKU-U']->getMappingStatus());
        self::assertNull($bySku['SKU-U']->getListingId());
        self::assertNull($bySku['SKU-U']->getProductId());

        self::assertSame(StockSnapshotMappingStatus::Mapped, $bySku['SKU-M']->getMappingStatus());
        self::assertSame($mapped->getId(), $bySku['SKU-M']->getListingId());
        self::assertSame($product->getId(), $bySku['SKU-M']->getProductId());

        self::assertSame(StockSnapshotMappingStatus::Ambiguous, $bySku['SKU-A']->getMappingStatus());
        self::assertNull($bySku['SKU-A']->getListingId());
        self::assertNull($bySku['SKU-A']->getProductId());

        self::assertSame(StockSnapshotMappingStatus::Mapped, $bySku['SKU-O']->getMappingStatus());
        self::assertSame($orphan->getId(), $bySku['SKU-O']->getListingId());
        self::assertNull($bySku['SKU-O']->getProductId());

        self::assertSame(MarketplaceType::OZON, $bySku['SKU-M']->getSource());
        self::assertSame(StockStatus::Available, $bySku['SKU-M']->getStatus());
        self::assertSame('20.000', $bySku['SKU-M']->getQuantity());
        self::assertSame('3.000', $bySku['SKU-M']->getReservedQuantity());
        self::assertSame('OF-M', $bySku['SKU-M']->getSourceOfferId());
        self::assertSame('fbs', $bySku['SKU-M']->getFulfillmentType());
    }

    public function testSingleSkuHasSingleSnapshotRowWithinSession(): void
    {
        $company = $this->createCompany(903);
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::OZON, SnapshotTriggerType::Manual);
        $session->markCompleted();
        $this->em->persist($session);

        $this->em->persist($this->raw($company->getId(), $session->getId(), '220282262', 11, 0, 'fbo', 'OF-220282262'));
        $this->em->flush();

        $action = self::getContainer()->get(NormalizeInventorySnapshotAction::class);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);

        self::assertSame(1, $this->em->getRepository(StockSnapshot::class)->count([
            'companyId' => $company->getId(),
            'snapshotSessionId' => $session->getId(),
            'source' => MarketplaceType::OZON,
            'sourceSku' => '220282262',
            'fulfillmentType' => 'fbo',
            'status' => StockStatus::Available,
        ]));
    }

    public function testCompletedSessionWithEmptyStocksMarksRawProcessedAndCreatesNoSnapshots(): void
    {
        $company = $this->createCompany(902);
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::OZON, SnapshotTriggerType::Manual);
        $session->markCompleted();
        $this->em->persist($session);

        $raw = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($session->getId())
            ->withSource(MarketplaceType::OZON)
            ->withResponseBody(['result' => ['items' => [['offer_id' => 'OF-EMPTY', 'stocks' => []]]]])
            ->build();
        $this->em->persist($raw);
        $this->em->flush();

        $action = self::getContainer()->get(NormalizeInventorySnapshotAction::class);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);

        $this->em->refresh($raw);
        self::assertTrue($raw->isProcessed());
        self::assertSame(0, $this->em->getRepository(StockSnapshot::class)->count(['companyId' => $company->getId()]));
    }

    public function testNormalizesWildberriesWarehouseStocksWithTransitStatuses(): void
    {
        $company = $this->createCompany(904);
        $session = new InventorySnapshotSession($company->getId(), MarketplaceType::WILDBERRIES, SnapshotTriggerType::Manual);
        $session->markCompleted();
        $this->em->persist($session);

        $product42 = new Product('30000000-0000-4000-8000-000000000904', $company);
        $product42->setSku('PRD-904-42')->setName('P904 42');
        $product44 = new Product('30000000-0000-4000-8000-000000000907', $company);
        $product44->setSku('PRD-904-44')->setName('P904 44');
        $listing42 = new MarketplaceListing('50000000-0000-4000-8000-000000000905', $company, $product42, MarketplaceType::WILDBERRIES);
        $listing42->setMarketplaceSku('100')->setMarketplaceVariantId('1')->setSize('42')->setPrice('100.00');
        $listing44 = new MarketplaceListing('50000000-0000-4000-8000-000000000907', $company, $product44, MarketplaceType::WILDBERRIES);
        $listing44->setMarketplaceSku('100')->setMarketplaceVariantId('2')->setSize('44')->setPrice('100.00');
        $this->em->persist($product42);
        $this->em->persist($product44);
        $this->em->persist($listing42);
        $this->em->persist($listing44);

        $firstRaw = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($session->getId())
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withPageNumber(1)
            ->withFetchedAt(new \DateTimeImmutable('2026-06-01T10:00:00+00:00'))
            ->withResponseBody(['data' => ['items' => [
                ['nmId' => 100, 'chrtId' => 1, 'warehouseId' => 507, 'warehouseName' => 'Коледино', 'regionName' => 'Центральный', 'quantity' => 4, 'inWayToClient' => 2, 'inWayFromClient' => 1],
            ]]])
            ->build();
        $secondRaw = InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($session->getId())
            ->withSource(MarketplaceType::WILDBERRIES)
            ->withPageNumber(2)
            ->withFetchedAt(new \DateTimeImmutable('2026-06-01T10:02:00+00:00'))
            ->withResponseBody(['data' => ['items' => [
                ['nmId' => 100, 'chrtId' => 2, 'warehouseId' => 507, 'warehouseName' => 'Коледино', 'regionName' => 'Центральный', 'quantity' => 6, 'inWayToClient' => 3, 'inWayFromClient' => 2],
            ]]])
            ->build();
        $this->em->persist($firstRaw);
        $this->em->persist($secondRaw);
        $this->em->flush();

        $action = self::getContainer()->get(NormalizeInventorySnapshotAction::class);
        $action($company->getId(), $session->getId(), MarketplaceType::WILDBERRIES);
        $action($company->getId(), $session->getId(), MarketplaceType::WILDBERRIES);

        $rows = $this->em->getRepository(StockSnapshot::class)->findBy(
            ['companyId' => $company->getId(), 'source' => MarketplaceType::WILDBERRIES],
            ['status' => 'ASC'],
        );
        self::assertCount(6, $rows);

        $byVariantAndStatus = [];
        foreach ($rows as $row) {
            $byVariantAndStatus[$row->getSourceSku()][$row->getStatus()->value] = $row;
            self::assertSame($session->getStartedAt()->format('Y-m-d H:i:s'), $row->getSnapshotAt()->format('Y-m-d H:i:s'));
            self::assertSame(StockSnapshotMappingStatus::Mapped, $row->getMappingStatus());
            self::assertSame('100', $row->getSourceOfferId());
            self::assertSame('1' === $row->getSourceSku() ? $listing42->getId() : $listing44->getId(), $row->getListingId());
            self::assertSame('1' === $row->getSourceSku() ? $product42->getId() : $product44->getId(), $row->getProductId());
        }

        self::assertSame('4.000', $byVariantAndStatus['1'][StockStatus::Available->value]->getQuantity());
        self::assertSame('2.000', $byVariantAndStatus['1'][StockStatus::InTransitToCustomer->value]->getQuantity());
        self::assertSame('1.000', $byVariantAndStatus['1'][StockStatus::InTransitFromCustomer->value]->getQuantity());
        self::assertSame('6.000', $byVariantAndStatus['2'][StockStatus::Available->value]->getQuantity());
        self::assertSame('3.000', $byVariantAndStatus['2'][StockStatus::InTransitToCustomer->value]->getQuantity());
        self::assertSame('2.000', $byVariantAndStatus['2'][StockStatus::InTransitFromCustomer->value]->getQuantity());

        $stockByListing = self::getContainer()->get(StockQtyByListingOnDateQuery::class)->execute($company->getId(), new \DateTimeImmutable());
        self::assertCount(2, $stockByListing);
        self::assertSame(4.0, $stockByListing[$listing42->getId()]);
        self::assertSame(6.0, $stockByListing[$listing44->getId()]);

        $reportPager = self::getContainer()->get(InventoryStockReportQuery::class)->getPage(
            companyId: $company->getId(),
            page: 1,
            perPage: 30,
            source: MarketplaceType::WILDBERRIES,
            snapshotDate: $session->getStartedAt(),
        );
        $reportRows = iterator_to_array($reportPager->getCurrentPageResults());
        self::assertCount(6, $reportRows);
        self::assertSame(['Коледино'], array_values(array_unique(array_column($reportRows, 'location_name'))));
        self::assertContains(StockStatus::Available->value, array_column($reportRows, 'status'));

        $locations = $this->em->getRepository(Location::class)->findBy([
            'companyId' => $company->getId(),
            'externalSystem' => MarketplaceType::WILDBERRIES,
        ]);
        self::assertCount(1, $locations);
        self::assertSame('507', $locations[0]->getExternalId());
        self::assertSame('Коледино', $locations[0]->getName());
        self::assertSame(['regionName' => 'Центральный'], $locations[0]->getMetadata());

        $this->em->refresh($firstRaw);
        $this->em->refresh($secondRaw);
        self::assertTrue($firstRaw->isProcessed());
        self::assertTrue($secondRaw->isProcessed());
    }

    public function testStockQtyFallsBackWhenLatestSourceSessionHasNoMappedListings(): void
    {
        $company = $this->createCompany(905);
        $location = LocationBuilder::aLocation()
            ->withCompanyId($company->getId())
            ->withExternalId('507')
            ->withCode('WB-507')
            ->withName('Коледино')
            ->build();
        $product = new Product('30000000-0000-4000-8000-000000000905', $company);
        $product->setSku('PRD-905')->setName('P905');
        $listing = new MarketplaceListing('50000000-0000-4000-8000-000000000906', $company, $product, MarketplaceType::WILDBERRIES);
        $listing->setMarketplaceSku('100')->setPrice('100.00');
        $olderSession = new InventorySnapshotSession($company->getId(), MarketplaceType::WILDBERRIES, SnapshotTriggerType::Manual);
        $newerSession = new InventorySnapshotSession($company->getId(), MarketplaceType::WILDBERRIES, SnapshotTriggerType::Manual);
        $olderSession->markCompleted();
        $newerSession->markCompleted();

        $this->em->persist($location);
        $this->em->persist($product);
        $this->em->persist($listing);
        $this->em->persist($olderSession);
        $this->em->persist($newerSession);
        $this->em->persist(StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($olderSession->getId())
            ->withSnapshotDate(new \DateTimeImmutable('2026-06-01'))
            ->withSnapshotAt(new \DateTimeImmutable('2026-06-01 10:00:00'))
            ->withLocationId($location->getId())
            ->withQuantity('12.000')
            ->withListingId($listing->getId())
            ->withProductId($product->getId())
            ->withSourceSku('100')
            ->withFulfillmentType('fbw')
            ->withMappingStatus(StockSnapshotMappingStatus::Mapped)
            ->build());
        $this->em->persist(StockSnapshotBuilder::aStockSnapshot()
            ->withCompanyId($company->getId())
            ->withSnapshotSessionId($newerSession->getId())
            ->withSnapshotDate(new \DateTimeImmutable('2026-06-02'))
            ->withSnapshotAt(new \DateTimeImmutable('2026-06-02 10:00:00'))
            ->withLocationId($location->getId())
            ->withQuantity('7.000')
            ->withListingId(null)
            ->withProductId(null)
            ->withSourceSku('200')
            ->withFulfillmentType('fbw')
            ->withMappingStatus(StockSnapshotMappingStatus::Unmapped)
            ->build());
        $this->em->flush();

        $stockByListing = self::getContainer()->get(StockQtyByListingOnDateQuery::class)->execute(
            $company->getId(),
            new \DateTimeImmutable('2026-06-02'),
        );

        self::assertSame([$listing->getId() => 12.0], $stockByListing);
    }

    private function raw(string $companyId, string $sessionId, string $sku, int $present, int $reserved, string $type, string $offerId): InventoryRawSnapshot
    {
        return InventoryRawSnapshotBuilder::aRawSnapshot()
            ->withCompanyId($companyId)->withSnapshotSessionId($sessionId)->withSource(MarketplaceType::OZON)
            ->withResponseBody(['result' => ['items' => [['offer_id' => $offerId, 'stocks' => [['sku' => $sku, 'type' => $type, 'present' => $present, 'reserved' => $reserved]]]]]])
            ->build();
    }

    private function createCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }
}
