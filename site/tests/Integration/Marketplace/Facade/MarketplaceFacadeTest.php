<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Facade;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceFacadeTest extends IntegrationTestCase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-000000000a01';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-000000000b01';

    private MarketplaceFacade $facade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facade = self::getContainer()->get(MarketplaceFacade::class);
    }

    public function testResolveEmptyArrayReturnsEmpty(): void
    {
        $result = $this->facade->resolveListingsToProducts(self::COMPANY_A_ID, []);

        self::assertSame([], $result);
    }

    public function testResolveInvalidCompanyIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->facade->resolveListingsToProducts(
            'not-a-uuid',
            ['55555555-5555-5555-5555-000000000001'],
        );
    }

    public function testResolveInvalidListingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->facade->resolveListingsToProducts(
            self::COMPANY_A_ID,
            ['not-a-uuid'],
        );
    }

    public function testResolveTooManyListingsThrows(): void
    {
        $manyIds = array_fill(0, 5001, '11111111-1111-1111-1111-111111111111');

        $this->expectException(\InvalidArgumentException::class);

        $this->facade->resolveListingsToProducts(self::COMPANY_A_ID, $manyIds);
    }

    public function testResolveReturnsListingToProductMap(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 'company-a-owner@example.test');
        $product = $this->seedProduct(
            $company,
            '33333333-3333-3333-3333-000000000001',
            'SKU-PROD-1',
            'Product 1',
        );

        $listingId = '55555555-5555-5555-5555-000000000001';
        $this->seedListing($company, $product, $listingId, 'PARENT-1', 'L');
        $this->em->flush();

        $result = $this->facade->resolveListingsToProducts(
            self::COMPANY_A_ID,
            [$listingId],
        );

        self::assertArrayHasKey($listingId, $result);
        self::assertSame($product->getId(), $result[$listingId]);
    }

    public function testResolveReturnsNullForOrphanListing(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 'company-a-orphan@example.test');

        $orphanId = '55555555-5555-5555-5555-000000000010';
        $this->seedListing($company, null, $orphanId, 'PARENT-ORPHAN', 'M');
        $this->em->flush();

        $result = $this->facade->resolveListingsToProducts(
            self::COMPANY_A_ID,
            [$orphanId],
        );

        self::assertArrayHasKey($orphanId, $result);
        self::assertNull($result[$orphanId]);
    }

    public function testResolveExcludesListingsFromOtherCompanies(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, 'iso-a@example.test');
        $companyB = $this->seedCompany(self::COMPANY_B_ID, 'iso-b@example.test', '22222222-2222-2222-2222-000000000b01');

        $productA = $this->seedProduct(
            $companyA,
            '33333333-3333-3333-3333-00000000a001',
            'SKU-A',
            'Product A',
        );

        $listingFromA = '55555555-5555-5555-5555-00000000a001';
        $this->seedListing($companyA, $productA, $listingFromA, 'PARENT-A', 'UNKNOWN');
        $this->em->flush();

        $result = $this->facade->resolveListingsToProducts(
            $companyB->getId(),
            [$listingFromA],
        );

        self::assertArrayNotHasKey($listingFromA, $result);
        self::assertSame([], $result);
    }

    public function testResolveExcludesNonExistentListings(): void
    {
        $this->seedCompany(self::COMPANY_A_ID, 'nonexistent@example.test');
        $this->em->flush();

        $nonExistentId = '99999999-9999-7999-9999-999999999999';

        $result = $this->facade->resolveListingsToProducts(
            self::COMPANY_A_ID,
            [$nonExistentId],
        );

        self::assertArrayNotHasKey($nonExistentId, $result);
        self::assertSame([], $result);
    }

    public function testResolveBatchOf100ListingsReturnsCorrectMap(): void
    {
        $company = $this->seedCompany(self::COMPANY_A_ID, 'batch@example.test');

        $productMappedId = '33333333-3333-3333-3333-000000000b01';
        $productMapped = $this->seedProduct($company, $productMappedId, 'SKU-MAPPED', 'Mapped Product');

        $listingIds = [];
        $expectedMap = [];

        // 60 листингов с привязкой к продукту, 40 orphan-листингов — итого 100.
        for ($i = 1; $i <= 100; ++$i) {
            $listingId = sprintf('66666666-6666-6666-6666-%012d', $i);
            $listingIds[] = $listingId;

            $product = $i <= 60 ? $productMapped : null;
            $this->seedListing(
                $company,
                $product,
                $listingId,
                sprintf('PARENT-%03d', $i),
                'UNKNOWN',
            );

            $expectedMap[$listingId] = $product?->getId();
        }

        $this->em->flush();

        $result = $this->facade->resolveListingsToProducts(self::COMPANY_A_ID, $listingIds);

        self::assertCount(100, $result);

        // Сравниваем как множества (порядок ключей в результате не гарантируется).
        ksort($result);
        ksort($expectedMap);
        self::assertSame($expectedMap, $result);
    }

    private function seedCompany(
        string $companyId,
        string $ownerEmail,
        ?string $ownerId = null,
    ): Company {
        $userBuilder = UserBuilder::aUser()->withEmail($ownerEmail);
        if ($ownerId !== null) {
            $userBuilder = $userBuilder->withId($ownerId);
        }
        $owner = $userBuilder->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->withName('Company ' . $companyId)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedProduct(
        Company $company,
        string $productId,
        string $sku,
        string $name,
    ): Product {
        $product = new Product($productId, $company);
        $product->setSku($sku);
        $product->setName($name);

        $this->em->persist($product);

        return $product;
    }

    private function seedListing(
        Company $company,
        ?Product $product,
        string $listingId,
        string $marketplaceSku,
        string $size,
    ): MarketplaceListing {
        $listing = new MarketplaceListing($listingId, $company, $product, MarketplaceType::OZON);
        $listing->setMarketplaceSku($marketplaceSku);
        $listing->setSize($size);
        $listing->setPrice('0.00');

        $this->em->persist($listing);

        return $listing;
    }
}
