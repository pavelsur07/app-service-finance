<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Finance\Entity\Document;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceSaleRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class MarketplaceSaleRepositoryTest extends IntegrationTestCase
{
    public function testDeleteByRawDocumentDeletesOnlyTargetRows(): void
    {
        $company1 = CompanyBuilder::aCompany()->withIndex(1)->build();
        $company2 = CompanyBuilder::aCompany()->withIndex(2)->build();
        $this->em->persist($company1);
        $this->em->persist($company2);

        $listing1Ozon = MarketplaceListingBuilder::aListing()
            ->forCompany($company1)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-1')
            ->build();
        $listing1Wb = MarketplaceListingBuilder::aListing()
            ->forCompany($company1)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-1')
            ->build();
        $listing2Ozon = MarketplaceListingBuilder::aListing()
            ->forCompany($company2)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-2')
            ->build();

        $this->em->persist($listing1Ozon);
        $this->em->persist($listing1Wb);
        $this->em->persist($listing2Ozon);

        $targetRawDocId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $otherRawDocId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        $target1 = MarketplaceSaleBuilder::aSale()
            ->forCompany($company1)
            ->forListing($listing1Ozon)
            ->withMarketplace(MarketplaceType::OZON)
            ->withExternalOrderId('target-1')
            ->build();
        $target1->setRawDocumentId($targetRawDocId);

        $target2 = MarketplaceSaleBuilder::aSale()
            ->forCompany($company1)
            ->forListing($listing1Ozon)
            ->withMarketplace(MarketplaceType::OZON)
            ->withExternalOrderId('target-2')
            ->build();
        $target2->setRawDocumentId($targetRawDocId);

        $otherDoc = MarketplaceSaleBuilder::aSale()
            ->forCompany($company1)
            ->forListing($listing1Ozon)
            ->withMarketplace(MarketplaceType::OZON)
            ->withExternalOrderId('other-doc')
            ->build();
        $otherDoc->setRawDocumentId($otherRawDocId);

        $otherMarketplace = MarketplaceSaleBuilder::aSale()
            ->forCompany($company1)
            ->forListing($listing1Wb)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withExternalOrderId('other-marketplace')
            ->build();
        $otherMarketplace->setRawDocumentId($targetRawDocId);

        $otherCompany = MarketplaceSaleBuilder::aSale()
            ->forCompany($company2)
            ->forListing($listing2Ozon)
            ->withMarketplace(MarketplaceType::OZON)
            ->withExternalOrderId('other-company')
            ->build();
        $otherCompany->setRawDocumentId($targetRawDocId);

        $finalDocument = new Document(Uuid::uuid4()->toString(), $company1);
        $this->em->persist($finalDocument);

        $lockedByDocument = MarketplaceSaleBuilder::aSale()
            ->forCompany($company1)
            ->forListing($listing1Ozon)
            ->withMarketplace(MarketplaceType::OZON)
            ->withExternalOrderId('locked-by-document')
            ->build();
        $lockedByDocument->setRawDocumentId($targetRawDocId);
        $lockedByDocument->setDocument($finalDocument);

        foreach ([$target1, $target2, $otherDoc, $otherMarketplace, $otherCompany, $lockedByDocument] as $sale) {
            $this->em->persist($sale);
        }

        $this->em->flush();

        /** @var MarketplaceSaleRepository $repository */
        $repository = self::getContainer()->get(MarketplaceSaleRepository::class);
        $deleted = $repository->deleteByRawDocument($company1, MarketplaceType::OZON, $targetRawDocId);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(2, $deleted);

        $remainingRows = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sales');
        self::assertSame(4, $remainingRows);

        $remainingTargetUnlocked = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :company AND marketplace = :marketplace AND raw_document_id = :rawDocId AND document_id IS NULL',
            [
                'company' => $company1->getId(),
                'marketplace' => MarketplaceType::OZON->value,
                'rawDocId' => $targetRawDocId,
            ],
        );
        self::assertSame(0, $remainingTargetUnlocked);

        $remainingOtherDoc = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id = :id',
            ['id' => 'other-doc'],
        );
        self::assertSame(1, $remainingOtherDoc);

        $remainingOtherMarketplace = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id = :id',
            ['id' => 'other-marketplace'],
        );
        self::assertSame(1, $remainingOtherMarketplace);

        $remainingOtherCompany = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id = :id',
            ['id' => 'other-company'],
        );
        self::assertSame(1, $remainingOtherCompany);

        $remainingLocked = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE external_order_id = :id AND document_id IS NOT NULL',
            ['id' => 'locked-by-document'],
        );
        self::assertSame(1, $remainingLocked);

        $remainingTargetLocked = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :company AND marketplace = :marketplace AND raw_document_id = :rawDocId AND document_id IS NOT NULL',
            [
                'company' => $company1->getId(),
                'marketplace' => MarketplaceType::OZON->value,
                'rawDocId' => $targetRawDocId,
            ],
        );
        self::assertSame(1, $remainingTargetLocked);
    }
}
