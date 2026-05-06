<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Processor;

use App\Finance\Entity\Document;
use App\Marketplace\Application\Processor\OzonSalesRawProcessor;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class OzonSalesRawProcessorTest extends IntegrationTestCase
{
    public function testCleanupDeletesOnlyStaleOpenRowsWithinSameCompanyMarketplaceAndPeriod(): void
    {
        $companyA = CompanyBuilder::aCompany()->withIndex(101)->build();
        $companyB = CompanyBuilder::aCompany()->withIndex(102)->build();
        $this->em->persist($companyA);
        $this->em->persist($companyB);

        $listingA = MarketplaceListingBuilder::aListing()->withIndex(1)->forCompany($companyA)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('oz-a')->build();
        $listingB = MarketplaceListingBuilder::aListing()->withIndex(2)->forCompany($companyB)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('oz-b')->build();
        $listingWb = MarketplaceListingBuilder::aListing()->withIndex(3)->forCompany($companyA)->withMarketplace(MarketplaceType::WILDBERRIES)->withMarketplaceSku('wb-a')->build();
        $this->em->persist($listingA); $this->em->persist($listingB); $this->em->persist($listingWb);

        $day = new \DateTimeImmutable('2026-03-10');
        $outside = new \DateTimeImmutable('2026-03-15');
        $rawDocA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $rawDocB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $stale = MarketplaceSaleBuilder::aSale()->withIndex(1)->forCompany($companyA)->forListing($listingA)->withMarketplace(MarketplaceType::OZON)->withExternalOrderId('stale')->withSaleDate($day)->build();
        $stale->setRawDocumentId($rawDocA);
        $staleStorno = MarketplaceSaleBuilder::aSale()->withIndex(2)->forCompany($companyA)->forListing($listingA)->withMarketplace(MarketplaceType::OZON)->withExternalOrderId('stale-storno')->withSaleDate($day)->withPricePerUnit('-200.00')->withTotalRevenue('-200.00')->build();
        $staleStorno->setRawDocumentId($rawDocA);
        $foreignCompany = MarketplaceSaleBuilder::aSale()->withIndex(3)->forCompany($companyB)->forListing($listingB)->withMarketplace(MarketplaceType::OZON)->withExternalOrderId('foreign-company')->withSaleDate($day)->build();
        $foreignCompany->setRawDocumentId($rawDocA);
        $foreignMarketplace = MarketplaceSaleBuilder::aSale()->withIndex(4)->forCompany($companyA)->forListing($listingWb)->withMarketplace(MarketplaceType::WILDBERRIES)->withExternalOrderId('foreign-marketplace')->withSaleDate($day)->build();
        $foreignMarketplace->setRawDocumentId($rawDocA);
        $outsidePeriod = MarketplaceSaleBuilder::aSale()->withIndex(5)->forCompany($companyA)->forListing($listingA)->withMarketplace(MarketplaceType::OZON)->withExternalOrderId('outside-period')->withSaleDate($outside)->build();
        $outsidePeriod->setRawDocumentId($rawDocA);

        $doc = new Document(Uuid::uuid4()->toString(), $companyA);
        $this->em->persist($doc);
        $closed = MarketplaceSaleBuilder::aSale()->withIndex(6)->forCompany($companyA)->forListing($listingA)->withMarketplace(MarketplaceType::OZON)->withExternalOrderId('closed')->withSaleDate($day)->build();
        $closed->setRawDocumentId($rawDocA);
        $closed->setDocument($doc);

        foreach ([$stale, $staleStorno, $foreignCompany, $foreignMarketplace, $outsidePeriod, $closed] as $sale) {
            $this->em->persist($sale);
        }

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocB)->forCompany($companyA)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build();
        $this->em->persist($rawDoc);
        $this->em->flush();

        self::getContainer()->get(OzonSalesRawProcessor::class)->processBatch($companyA->getId(), MarketplaceType::OZON, [[
            'operation_id' => '2001',
            'operation_date' => '2026-03-10 12:00:00',
            'accruals_for_sale' => 100,
            'type' => 'orders',
            'posting' => ['posting_number' => 'posting-b'],
            'items' => [['sku' => 'oz-a', 'name' => 'SKU A']],
        ]], $rawDocB);

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='stale'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='stale-storno'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='posting-b' AND raw_document_id=:raw", ['raw' => $rawDocB]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='closed' AND document_id IS NOT NULL"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='foreign-company'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='foreign-marketplace'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id='outside-period'"));
    }
}
