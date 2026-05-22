<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Finance\Entity\Document;
use App\Marketplace\Application\Service\WbGeneratedRowsSafeReplaceService;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceCostOperationType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\WbGeneratedRowsConflictException;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class WbFinancialReportRefreshTest extends IntegrationTestCase
{
    public function testOpenSalesReturnsCostsRowsAreDeleted(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $this->em->persist($company);
        $listing = $this->buildListing($company);
        $this->em->persist($listing);
        $rawDocId = '81111111-1111-4111-8111-111111111111';
        $day = new \DateTimeImmutable('2026-03-12');

        $sale = (new MarketplaceSale(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::WILDBERRIES))
            ->setExternalOrderId('sale-1')->setSaleDate($day)->setQuantity(1)->setPricePerUnit('100')->setTotalRevenue('100')->setRawDocumentId($rawDocId);
        $return = (new MarketplaceReturn(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::WILDBERRIES))
            ->setExternalReturnId('ret-1')->setReturnDate($day)->setQuantity(1)->setRefundAmount('50')->setRawDocumentId($rawDocId);
        $cost = (new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, null))
            ->setExternalId('cost-1')->setCostDate($day)->setAmount('10')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocId);
        $this->em->persist($sale);$this->em->persist($return);$this->em->persist($cost);$this->em->flush();

        self::getContainer()->get(WbGeneratedRowsSafeReplaceService::class)->cleanupForRawDocument($company, $rawDocId, $day);

        $conn=$this->em->getConnection();
        self::assertSame(0,(int)$conn->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE raw_document_id=:id',['id'=>$rawDocId]));
        self::assertSame(0,(int)$conn->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE raw_document_id=:id',['id'=>$rawDocId]));
        self::assertSame(0,(int)$conn->fetchOne('SELECT COUNT(*) FROM marketplace_costs WHERE raw_document_id=:id',['id'=>$rawDocId]));
    }

    public function testLinkedRowsCauseConflictAndNoPartialDeletion(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $this->em->persist($company);
        $listing = $this->buildListing($company);
        $this->em->persist($listing);
        $rawDocId = '83333333-3333-4333-8333-333333333333';
        $day = new \DateTimeImmutable('2026-03-12');

        $document = new Document(Uuid::uuid4()->toString(), $company);
        $this->em->persist($document);

        $openSale = (new MarketplaceSale(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::WILDBERRIES))
            ->setExternalOrderId('open-sale')->setSaleDate($day)->setQuantity(1)->setPricePerUnit('100')->setTotalRevenue('100')->setRawDocumentId($rawDocId);
        $linkedSale = (new MarketplaceSale(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::WILDBERRIES))
            ->setExternalOrderId('linked-sale')->setSaleDate($day)->setQuantity(1)->setPricePerUnit('100')->setTotalRevenue('100')->setRawDocumentId($rawDocId)->setDocument($document);
        $openReturn = (new MarketplaceReturn(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::WILDBERRIES))
            ->setExternalReturnId('open-ret')->setReturnDate($day)->setQuantity(1)->setRefundAmount('50')->setRawDocumentId($rawDocId);
        $linkedReturn = (new MarketplaceReturn(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::WILDBERRIES))
            ->setExternalReturnId('linked-ret')->setReturnDate($day)->setQuantity(1)->setRefundAmount('50')->setRawDocumentId($rawDocId)->setDocument($document);
        $openCost = (new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, null))
            ->setExternalId('open-cost')->setCostDate($day)->setAmount('10')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocId);
        $linkedCost = (new MarketplaceCost(Uuid::uuid4()->toString(), $company, MarketplaceType::WILDBERRIES, null))
            ->setExternalId('linked-cost')->setCostDate($day)->setAmount('11')->setOperationType(MarketplaceCostOperationType::CHARGE)->setRawDocumentId($rawDocId)->setDocument($document);
        foreach ([$openSale,$linkedSale,$openReturn,$linkedReturn,$openCost,$linkedCost] as $e) {$this->em->persist($e);} $this->em->flush();

        $this->expectException(WbGeneratedRowsConflictException::class);
        try { self::getContainer()->get(WbGeneratedRowsSafeReplaceService::class)->cleanupForRawDocument($company, $rawDocId, $day);} finally {
            $conn=$this->em->getConnection();
            self::assertSame(2,(int)$conn->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE raw_document_id=:id',['id'=>$rawDocId]));
            self::assertSame(2,(int)$conn->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE raw_document_id=:id',['id'=>$rawDocId]));
            self::assertSame(2,(int)$conn->fetchOne('SELECT COUNT(*) FROM marketplace_costs WHERE raw_document_id=:id',['id'=>$rawDocId]));
        }
    }

    private function buildListing(Company $company): MarketplaceListing
    {
        $listing = new MarketplaceListing(Uuid::uuid4()->toString(), $company, null, MarketplaceType::WILDBERRIES);
        $listing->setMarketplaceSku('sku-'.substr(Uuid::uuid4()->toString(),0,8));
        $listing->setPrice('100');

        return $listing;
    }
}
