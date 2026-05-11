<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory\Application;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Inventory\Application\NormalizeInventorySnapshotAction;
use App\Inventory\Entity\InventoryRawSnapshot;
use App\Inventory\Entity\InventorySnapshotSession;
use App\Inventory\Entity\StockSnapshot;
use App\Inventory\Enum\SnapshotTriggerType;
use App\Inventory\Enum\StockSnapshotMappingStatus;
use App\Inventory\Enum\StockStatus;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Inventory\InventoryRawSnapshotBuilder;
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
        $this->em->persist($mapped);$this->em->persist($orphan);$this->em->persist($amb1);$this->em->persist($amb2);
        $this->em->flush();

        $action = self::getContainer()->get(NormalizeInventorySnapshotAction::class);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);
        $action($company->getId(), $session->getId(), MarketplaceType::OZON);

        $rows = $this->em->getRepository(StockSnapshot::class)->findBy(['companyId' => $company->getId()]);
        self::assertCount(4, $rows);

        $bySku = [];
        foreach ($rows as $row) { $bySku[$row->getSourceSku()] = $row; }

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
        $this->em->persist($user); $this->em->persist($company); $this->em->flush();
        return $company;
    }
}
