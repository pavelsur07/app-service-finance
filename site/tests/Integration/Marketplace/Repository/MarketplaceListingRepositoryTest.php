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
            $this->companyId($companyA),
            MarketplaceType::WILDBERRIES,
            'variant-a',
        ));
        self::assertSame($second, $this->repository->findByMarketplaceVariantId(
            $this->companyId($companyA),
            MarketplaceType::WILDBERRIES,
            'variant-b',
        ));
        // Чужая компания не видит вариант, даже зная его идентификатор.
        self::assertNull($this->repository->findByMarketplaceVariantId(
            $this->companyId($companyB),
            MarketplaceType::WILDBERRIES,
            'variant-b',
        ));
        self::assertNull($this->repository->findByMarketplaceVariantId(
            $this->companyId($companyA),
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

    public function testMarketplaceSkuLookupIsScopedToCompany(): void
    {
        [$companyA, $companyB] = $this->seedCompanies();

        $own = $this->seedListing($companyA, 11, MarketplaceType::WILDBERRIES, 'shared-sku', null);
        $foreign = $this->seedListing($companyB, 12, MarketplaceType::WILDBERRIES, 'shared-sku', null);
        $this->em->flush();

        // Один и тот же SKU у двух компаний: каждая видит ровно свой листинг.
        // Без фильтра по компании обе выборки нашли бы по два совпадения и
        // вернули бы null, поэтому проверка именно на конкретный листинг.
        self::assertSame($own, $this->repository->findByMarketplaceSku(
            $this->companyId($companyA),
            MarketplaceType::WILDBERRIES,
            'shared-sku',
        ));
        self::assertSame($foreign, $this->repository->findByMarketplaceSku(
            $this->companyId($companyB),
            MarketplaceType::WILDBERRIES,
            'shared-sku',
        ));

        // Маркетплейс тоже ограничивает: того же SKU на Ozon нет.
        self::assertNull($this->repository->findByMarketplaceSku(
            $this->companyId($companyA),
            MarketplaceType::OZON,
            'shared-sku',
        ));
    }

    public function testSupplierSkuLookupIsScopedToCompanyAndRejectsAmbiguity(): void
    {
        [$companyA, $companyB] = $this->seedCompanies();

        $own = $this->seedListing($companyA, 21, MarketplaceType::WILDBERRIES, 'sku-21', null);
        $own->setSupplierSku('shared-supplier-sku');
        $foreign = $this->seedListing($companyB, 22, MarketplaceType::WILDBERRIES, 'sku-22', null);
        $foreign->setSupplierSku('shared-supplier-sku');
        $this->em->flush();

        // Артикул поставщика совпадает у двух компаний — каждая находит свой.
        self::assertSame($own, $this->repository->findBySupplierSku(
            $this->companyId($companyA),
            MarketplaceType::WILDBERRIES,
            'shared-supplier-sku',
        ));
        self::assertSame($foreign, $this->repository->findBySupplierSku(
            $this->companyId($companyB),
            MarketplaceType::WILDBERRIES,
            'shared-supplier-sku',
        ));

        // Два своих листинга на один артикул — выбирать не из чего, возвращается null.
        $duplicate = $this->seedListing($companyA, 23, MarketplaceType::WILDBERRIES, 'sku-23', null);
        $duplicate->setSupplierSku('shared-supplier-sku');
        $this->em->flush();

        self::assertNull($this->repository->findBySupplierSku(
            $this->companyId($companyA),
            MarketplaceType::WILDBERRIES,
            'shared-supplier-sku',
        ));
    }

    private function companyId(Company $company): string
    {
        return (string) $company->getId();
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
