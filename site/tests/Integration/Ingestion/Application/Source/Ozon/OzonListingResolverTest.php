<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application\Source\Ozon;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Service\ListingResolverRegistry;
use App\Ingestion\Application\Source\Ozon\OzonListingResolver;
use App\Ingestion\Enum\IngestSource;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
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
        $marketplaceResolutionFromLaterItem = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'items' => [
                ['name' => 'Item without SKU'],
                ['sku' => 'marketplace-sku-1'],
            ],
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
        $createdResolution = $registry->resolve(IngestSource::OZON, (string) $company->getId(), [
            'offer_id' => 'new-offer',
            'sku' => 'new-marketplace-sku',
            'name' => 'New Ozon Listing',
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
        self::assertNotNull($marketplaceResolutionFromLaterItem);
        self::assertSame($listing->getId(), $marketplaceResolutionFromLaterItem->listingId);
        self::assertSame('marketplace-sku-1', $marketplaceResolutionFromLaterItem->listingSku);
        self::assertNotNull($missingResolution);
        self::assertNull($missingResolution->listingId);
        self::assertSame('missing-offer', $missingResolution->listingSku);
        self::assertNotNull($ambiguousSupplierResolution);
        self::assertNull($ambiguousSupplierResolution->listingId);
        self::assertSame('ambiguous-offer', $ambiguousSupplierResolution->listingSku);
        self::assertNotNull($ambiguousMarketplaceResolution);
        self::assertNull($ambiguousMarketplaceResolution->listingId);
        self::assertSame('ambiguous-marketplace-sku', $ambiguousMarketplaceResolution->listingSku);
        self::assertNotNull($createdResolution);
        self::assertNotNull($createdResolution->listingId);
        self::assertSame('new-marketplace-sku', $createdResolution->listingSku);

        /** @var MarketplaceListingRepository $listingRepository */
        $listingRepository = self::getContainer()->get(MarketplaceListingRepository::class);
        $createdListing = $listingRepository->findByMarketplaceSku((string) $company->getId(), MarketplaceType::OZON, 'new-marketplace-sku');
        self::assertNotNull($createdListing);
        self::assertSame($createdResolution->listingId, $createdListing->getId());
        self::assertSame('new-offer', $createdListing->getSupplierSku());
        self::assertSame('New Ozon Listing', $createdListing->getName());

        /** @var OzonListingResolver $resolver */
        $resolver = self::getContainer()->get(OzonListingResolver::class);
        $readOnlyResolution = $resolver->resolveManyReadOnly((string) $company->getId(), [
            'dry-run-row' => [
                'offer_id' => 'dry-run-offer',
                'sku' => 'dry-run-marketplace-sku',
                'name' => 'Dry Run Listing',
            ],
        ])['dry-run-row'];

        self::assertNotNull($readOnlyResolution);
        self::assertNull($readOnlyResolution->listingId);
        self::assertSame('dry-run-offer', $readOnlyResolution->listingSku);
        self::assertNull($listingRepository->findByMarketplaceSku((string) $company->getId(), MarketplaceType::OZON, 'dry-run-marketplace-sku'));

        $missingPreview = $resolver->previewMany((string) $company->getId(), [
            'preview-row' => [
                'offer_id' => 'preview-offer',
                'sku' => 'preview-marketplace-sku',
                'name' => 'Preview Listing',
            ],
        ])['preview-row'];
        $ambiguousPreview = $resolver->previewMany((string) $company->getId(), [
            'ambiguous-row' => [
                'sku' => 'ambiguous-marketplace-sku',
            ],
        ])['ambiguous-row'];

        self::assertTrue($missingPreview->wouldCreate);
        self::assertNotNull($missingPreview->resolution);
        self::assertNull($missingPreview->resolution->listingId);
        self::assertSame('preview-marketplace-sku', $missingPreview->resolution->listingSku);
        self::assertNull($listingRepository->findByMarketplaceSku((string) $company->getId(), MarketplaceType::OZON, 'preview-marketplace-sku'));
        self::assertFalse($ambiguousPreview->wouldCreate);
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
