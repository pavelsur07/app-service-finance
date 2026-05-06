<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application\Processor;

use App\Finance\Entity\Document;
use App\Marketplace\Application\Processor\OzonReturnsRawProcessor;
use App\Marketplace\Entity\MarketplaceReturn;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class OzonReturnsRawProcessorTest extends IntegrationTestCase
{
    public function testCleanupRemovesOnlyStaleOpenReturns(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(201)->build();
        $foreignCompany = CompanyBuilder::aCompany()->withIndex(202)->build();
        $this->em->persist($company);
        $this->em->persist($foreignCompany);
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('oz-ret')->build();
        $foreignListing = MarketplaceListingBuilder::aListing()->forCompany($foreignCompany)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('oz-ret-foreign')->build();
        $this->em->persist($listing);
        $this->em->persist($foreignListing);

        $day = new \DateTimeImmutable('2026-03-11');
        $outside = new \DateTimeImmutable('2026-03-18');
        $rawDocA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa11';
        $rawDocB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbb11';

        $stale = new MarketplaceReturn(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::OZON);
        $stale->setExternalReturnId('stale-ret')->setReturnDate($day)->setQuantity(1)->setRefundAmount('10')->setRawDocumentId($rawDocA);

        $doc = new Document(Uuid::uuid4()->toString(), $company);
        $this->em->persist($doc);
        $closed = new MarketplaceReturn(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::OZON);
        $closed->setExternalReturnId('closed-ret')->setReturnDate($day)->setQuantity(1)->setRefundAmount('10')->setRawDocumentId($rawDocA)->setDocument($doc);
        $foreignStale = new MarketplaceReturn(Uuid::uuid4()->toString(), $foreignCompany, $foreignListing, MarketplaceType::OZON);
        $foreignStale->setExternalReturnId('foreign-ret')->setReturnDate($day)->setQuantity(1)->setRefundAmount('10')->setRawDocumentId($rawDocA);
        $outsidePeriod = new MarketplaceReturn(Uuid::uuid4()->toString(), $company, $listing, MarketplaceType::OZON);
        $outsidePeriod->setExternalReturnId('outside-ret')->setReturnDate($outside)->setQuantity(1)->setRefundAmount('10')->setRawDocumentId($rawDocA);

        $this->em->persist($stale);
        $this->em->persist($closed);
        $this->em->persist($foreignStale);
        $this->em->persist($outsidePeriod);
        $this->em->persist(MarketplaceRawDocumentBuilder::aDocument()->withId($rawDocB)->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withPeriod($day, $day)->build());
        $this->em->flush();

        self::getContainer()->get(OzonReturnsRawProcessor::class)->processBatch($company->getId(), MarketplaceType::OZON, [[
            'operation_id' => 'ret-1', 'operation_date' => '2026-03-11 10:00:00', 'posting' => ['posting_number' => 'ret-b'],
            'accruals_for_sale' => -10, 'amount' => -10, 'operation_type_name' => 'Возврат', 'items' => [['sku' => 'oz-ret', 'name' => 'N']],
        ]], $rawDocB);

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_returns WHERE external_return_id='stale-ret'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_returns WHERE external_return_id='closed-ret' AND document_id IS NOT NULL"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_returns WHERE external_return_id='ret-b' AND raw_document_id=:raw", ['raw' => $rawDocB]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_returns WHERE external_return_id='foreign-ret'"));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM marketplace_returns WHERE external_return_id='outside-ret'"));
    }
}
