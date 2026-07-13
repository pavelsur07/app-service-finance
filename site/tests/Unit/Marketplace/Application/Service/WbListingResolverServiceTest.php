<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Application\Service;

use App\Marketplace\Application\Service\WbListingResolverService;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\WbBarcodeUpsertQuery;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class WbListingResolverServiceTest extends TestCase
{
    public function testExistingListingReceivesVariantId(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $listing = $this->listing($company, '123', '42');
        $repository = $this->createMock(MarketplaceListingRepository::class);
        $repository->method('findByNmIdAndSize')->willReturn($listing);
        $repository->method('findByMarketplaceVariantId')->willReturn(null);

        $resolved = $this->resolver($repository)->resolve($company, '123', '42', marketplaceVariantId: '9001');

        self::assertSame($listing, $resolved);
        self::assertSame('9001', $listing->getMarketplaceVariantId());
    }

    public function testVariantIdentityConflictIsRejected(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $byNaturalKey = $this->listing($company, '123', '42');
        $byVariant = $this->listing($company, '456', '44');
        $byVariant->setMarketplaceVariantId('9001');

        $repository = $this->createMock(MarketplaceListingRepository::class);
        $repository->method('findByNmIdAndSize')->willReturn($byNaturalKey);
        $repository->method('findByMarketplaceVariantId')->willReturn($byVariant);

        $this->expectException(\DomainException::class);

        $this->resolver($repository)->resolve($company, '123', '42', marketplaceVariantId: '9001');
    }

    public function testCatalogVariantFoundOnlyByVariantRejectsMismatchedNaturalIdentity(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $byVariant = $this->listing($company, '456', '44');
        $byVariant->setMarketplaceVariantId('9001');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('WB chrtId=9001 belongs to another listing.');

        $this->resolver($this->createMock(MarketplaceListingRepository::class))->resolveCatalogVariant(
            company: $company,
            nmId: '123',
            size: '42',
            marketplaceVariantId: '9001',
            listingByNaturalKey: null,
            listingByVariant: $byVariant,
        );
    }

    public function testLegacyResolveDoesNotClearKnownVariantId(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $listing = $this->listing($company, '123', '42');
        $listing->setMarketplaceVariantId('9001');
        $repository = $this->createMock(MarketplaceListingRepository::class);
        $repository->method('findByNmIdAndSize')->willReturn($listing);

        $this->resolver($repository)->resolve($company, '123', '42');

        self::assertSame('9001', $listing->getMarketplaceVariantId());
    }

    private function resolver(MarketplaceListingRepository $repository): WbListingResolverService
    {
        return new WbListingResolverService(
            $repository,
            $this->createMock(MarketplaceListingBarcodeRepository::class),
            new WbBarcodeUpsertQuery($this->createMock(Connection::class)),
            $this->createMock(EntityManagerInterface::class),
        );
    }

    private function listing(\App\Company\Entity\Company $company, string $nmId, string $size): MarketplaceListing
    {
        $listing = new MarketplaceListing(
            '55555555-5555-4555-8555-'.str_pad($nmId, 12, '0', \STR_PAD_LEFT),
            $company,
            null,
            MarketplaceType::WILDBERRIES,
        );
        $listing->setMarketplaceSku($nmId);
        $listing->setSize($size);
        $listing->setPrice('0');

        return $listing;
    }
}
