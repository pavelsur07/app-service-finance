<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Repository;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceListingRepositoryTest extends IntegrationTestCase
{
    private MarketplaceListingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(MarketplaceListingRepository::class);
    }

    public function testVariantLookupsAreScopedAndDeduplicateBulkInput(): void
    {
        [$companyA, $companyB] = $this->seedCompanies();

        $first = $this->seedListing($companyA, 1, MarketplaceType::WILDBERRIES, 'wb-parent', 'variant-a');
        $second = $this->seedListing($companyA, 2, MarketplaceType::WILDBERRIES, 'wb-parent', 'variant-b');
        $this->seedListing($companyB, 3, MarketplaceType::WILDBERRIES, 'other-company', 'variant-a');
        $this->seedListing($companyA, 4, MarketplaceType::OZON, 'ozon-offer', 'variant-a');
        $this->seedListing($companyA, 5, MarketplaceType::WILDBERRIES, 'without-variant', null);
        $this->em->flush();

        self::assertSame($first, $this->repository->findByMarketplaceVariantId(
            $companyA,
            MarketplaceType::WILDBERRIES,
            'variant-a',
        ));
        self::assertSame($second, $this->repository->findByMarketplaceVariantId(
            $companyA->getId(),
            MarketplaceType::WILDBERRIES,
            'variant-b',
        ));
        self::assertNull($this->repository->findByMarketplaceVariantId(
            $companyB,
            MarketplaceType::WILDBERRIES,
            'variant-b',
        ));
        self::assertNull($this->repository->findByMarketplaceVariantId(
            $companyA,
            MarketplaceType::OZON,
            'variant-b',
        ));

        $bulk = $this->repository->findAllByCompanyMarketplaceAndMarketplaceVariantIds(
            $companyA->getId(),
            MarketplaceType::WILDBERRIES,
            ['variant-b', 'variant-a', 'variant-a', 'missing'],
        );

        self::assertSame([$first, $second], $bulk);
        self::assertSame([], $this->repository->findAllByCompanyMarketplaceAndMarketplaceVariantIds(
            $companyA->getId(),
            MarketplaceType::WILDBERRIES,
            [],
        ));
    }

    /** @return array{Company, Company} */
    private function seedCompanies(): array
    {
        $companyA = CompanyBuilder::aCompany()
            ->withIndex(41)
            ->withOwner(UserBuilder::aUser()->withIndex(41)->build())
            ->build();
        $companyB = CompanyBuilder::aCompany()
            ->withIndex(42)
            ->withOwner(UserBuilder::aUser()->withIndex(42)->build())
            ->build();

        foreach ([$companyA, $companyB] as $company) {
            $this->em->persist($company->getUser());
            $this->em->persist($company);
        }

        return [$companyA, $companyB];
    }

    private function seedListing(
        Company $company,
        int $index,
        MarketplaceType $marketplace,
        string $marketplaceSku,
        ?string $marketplaceVariantId,
    ): MarketplaceListing {
        $listing = MarketplaceListingBuilder::aListing()
            ->withIndex($index)
            ->forCompany($company)
            ->withMarketplace($marketplace)
            ->withMarketplaceSku($marketplaceSku)
            ->withMarketplaceVariantId($marketplaceVariantId)
            ->build();
        $listing->setSize((string) $index);
        $this->em->persist($listing);

        return $listing;
    }
}
