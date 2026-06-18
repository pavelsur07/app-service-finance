<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Service\ListingResolverRegistry;
use App\Ingestion\Enum\IngestSource;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class OzonListingResolverTest extends IntegrationTestCase
{
    public function testResolvesBySupplierSkuAndMarketplaceSkuFallback(): void
    {
        $company = $this->createCompany();
        $listing = new MarketplaceListing(
            id: Uuid::uuid7()->toString(),
            company: $company,
            product: null,
            marketplace: MarketplaceType::OZON,
        );
        $listing->setSupplierSku('offer-1');
        $listing->setMarketplaceSku('marketplace-sku-1');
        $listing->setPrice('1000.00');

        $this->em->persist($listing);
        $this->em->persist($this->newListing($company, 'ambiguous-offer', 'ambiguous-marketplace-sku', 'UNKNOWN'));
        $this->em->persist($this->newListing($company, 'ambiguous-offer', 'ambiguous-marketplace-sku', 'L'));
        $this->em->flush();
        $this->em->clear();

        /** @var ListingResolverRegistry $registry */
        $registry = self::getContainer()->get(ListingResolverRegistry::class);

        $supplierResolution = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'offer_id' => 'offer-1',
            'sku' => 'marketplace-sku-1',
        ]);
        $supplierMissWithSkuFallback = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'offer_id' => 'missing-offer-with-fallback',
            'sku' => 'marketplace-sku-1',
        ]);
        $marketplaceResolution = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'item' => ['sku' => 'marketplace-sku-1'],
        ]);
        $missingResolution = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'offer_id' => 'missing-offer',
        ]);
        $ambiguousSupplierResolution = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'offer_id' => 'ambiguous-offer',
        ]);
        $ambiguousMarketplaceResolution = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'sku' => 'ambiguous-marketplace-sku',
        ]);

        self::assertNotNull($supplierResolution);
        self::assertSame($listing->getId(), $supplierResolution->listingId);
        self::assertSame('offer-1', $supplierResolution->listingSku);
        self::assertNotNull($supplierMissWithSkuFallback);
        self::assertSame($listing->getId(), $supplierMissWithSkuFallback->listingId);
        self::assertSame('marketplace-sku-1', $supplierMissWithSkuFallback->listingSku);
        self::assertNotNull($marketplaceResolution);
        self::assertSame($listing->getId(), $marketplaceResolution->listingId);
        self::assertSame('marketplace-sku-1', $marketplaceResolution->listingSku);
        self::assertNotNull($missingResolution);
        self::assertNull($missingResolution->listingId);
        self::assertSame('missing-offer', $missingResolution->listingSku);
        self::assertNotNull($ambiguousSupplierResolution);
        self::assertNull($ambiguousSupplierResolution->listingId);
        self::assertSame('ambiguous-offer', $ambiguousSupplierResolution->listingSku);
        self::assertNotNull($ambiguousMarketplaceResolution);
        self::assertNull($ambiguousMarketplaceResolution->listingId);
        self::assertSame('ambiguous-marketplace-sku', $ambiguousMarketplaceResolution->listingSku);
    }

    private function createCompany(): Company
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('ozon-listing-resolver@example.com');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Ozon Listing Resolver Company');

        $this->em->persist($user);
        $this->em->persist($company);

        return $company;
    }

    private function newListing(
        Company $company,
        string $supplierSku,
        string $marketplaceSku,
        string $size,
    ): MarketplaceListing {
        $listing = new MarketplaceListing(
            id: Uuid::uuid7()->toString(),
            company: $company,
            product: null,
            marketplace: MarketplaceType::OZON,
        );
        $listing->setSupplierSku($supplierSku);
        $listing->setMarketplaceSku($marketplaceSku);
        $listing->setSize($size);
        $listing->setPrice('1000.00');

        return $listing;
    }
}
