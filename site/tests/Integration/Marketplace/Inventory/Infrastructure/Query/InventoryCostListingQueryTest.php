<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Inventory\Infrastructure\Query;

use App\Catalog\Entity\Product;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Inventory\Infrastructure\Query\InventoryCostListingQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class InventoryCostListingQueryTest extends IntegrationTestCase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-00000000000a';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-00000000000b';
    private const OWNER_A_ID   = '22222222-2222-2222-2222-00000000000a';
    private const OWNER_B_ID   = '22222222-2222-2222-2222-00000000000b';

    private InventoryCostListingQuery $query;

    protected function setUp(): void
    {
        parent::setUp();

        $this->query = self::getContainer()->get(InventoryCostListingQuery::class);
    }

    public function testReturnsAllListingsOfCompanyWhenSearchIsNull(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-001', 'Foo Bar', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-002', 'Baz Qux', null, MarketplaceType::WILDBERRIES);
        $this->seedListing($companyA, 'sku-003', 'Quux',    null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, null);

        self::assertCount(3, $rows);
    }

    public function testSearchMatchesByListingName(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-001', 'Кружка зелёная', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-002', 'Тарелка синяя',  null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, 'кружка');

        self::assertCount(1, $rows);
        self::assertSame('sku-001', $rows[0]['marketplace_sku']);
    }

    public function testSearchMatchesByMarketplaceSku(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'WB-12345', 'Listing One', null, MarketplaceType::WILDBERRIES);
        $this->seedListing($companyA, 'WB-67890', 'Listing Two', null, MarketplaceType::WILDBERRIES);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, '12345');

        self::assertCount(1, $rows);
        self::assertSame('WB-12345', $rows[0]['marketplace_sku']);
    }

    public function testSearchMatchesBySupplierSku(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-A', 'Listing A', 'VENDOR-AAA', MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-B', 'Listing B', 'VENDOR-BBB', MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, 'AAA');

        self::assertCount(1, $rows);
        self::assertSame('sku-A', $rows[0]['marketplace_sku']);
    }

    public function testSearchMatchesByProductName(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $product = $this->seedProduct($companyA, 'PRD-001', 'Карандаш красный');
        $this->seedListing($companyA, 'sku-mp-1', 'Listing Title', null, MarketplaceType::OZON, $product);
        $this->seedListing($companyA, 'sku-mp-2', 'Other Title',   null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, 'карандаш');

        self::assertCount(1, $rows);
        self::assertSame('sku-mp-1', $rows[0]['marketplace_sku']);
    }

    public function testSearchMatchesByProductSku(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $product = $this->seedProduct($companyA, 'PRD-99-XYZ', 'Some product');
        $this->seedListing($companyA, 'sku-with-prd', 'L1', null, MarketplaceType::OZON, $product);
        $this->seedListing($companyA, 'sku-without',  'L2', null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, 'XYZ');

        self::assertCount(1, $rows);
        self::assertSame('sku-with-prd', $rows[0]['marketplace_sku']);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-1', 'lower-case-name', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-2', 'Other',           null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, 'LOWER');

        self::assertCount(1, $rows);
        self::assertSame('sku-1', $rows[0]['marketplace_sku']);
    }

    public function testUnderscoreInSearchIsTreatedAsLiteralNotWildcard(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-l1', 'ABC_X', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-l2', 'ABC1X', null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, '_X');

        self::assertCount(1, $rows);
        self::assertSame('sku-l1', $rows[0]['marketplace_sku']);
    }

    public function testPercentInSearchIsTreatedAsLiteralNotWildcard(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-l1', 'foo%bar', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-l2', 'fooXbar', null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, null, '%');

        self::assertCount(1, $rows);
        self::assertSame('sku-l1', $rows[0]['marketplace_sku']);
    }

    public function testIDORListingFromOtherCompanyNotReturned(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');
        $companyB = $this->seedCompany(self::COMPANY_B_ID, self::OWNER_B_ID, 'b@example.test');

        $this->seedListing($companyA, 'sku-A1', 'Shared name', null, MarketplaceType::OZON);
        $this->seedListing($companyB, 'sku-B1', 'Shared name', null, MarketplaceType::OZON);

        $this->em->flush();

        $rowsA = $this->fetch(self::COMPANY_A_ID, null, 'shared');
        $rowsB = $this->fetch(self::COMPANY_B_ID, null, 'shared');

        self::assertCount(1, $rowsA);
        self::assertSame('sku-A1', $rowsA[0]['marketplace_sku']);

        self::assertCount(1, $rowsB);
        self::assertSame('sku-B1', $rowsB[0]['marketplace_sku']);
    }

    public function testSearchAndMarketplaceFiltersCombineWithAnd(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-1', 'Match Word', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-2', 'Match Word', null, MarketplaceType::WILDBERRIES);
        $this->seedListing($companyA, 'sku-3', 'Other',      null, MarketplaceType::OZON);

        $this->em->flush();

        $rows = $this->fetch(self::COMPANY_A_ID, MarketplaceType::OZON->value, 'match');

        self::assertCount(1, $rows);
        self::assertSame('sku-1', $rows[0]['marketplace_sku']);
    }

    public function testEmptyAndNullSearchAreEquivalent(): void
    {
        $companyA = $this->seedCompany(self::COMPANY_A_ID, self::OWNER_A_ID, 'a@example.test');

        $this->seedListing($companyA, 'sku-1', 'Alpha', null, MarketplaceType::OZON);
        $this->seedListing($companyA, 'sku-2', 'Beta',  null, MarketplaceType::OZON);

        $this->em->flush();

        $rowsNull  = $this->fetch(self::COMPANY_A_ID, null, null);
        $rowsEmpty = $this->fetch(self::COMPANY_A_ID, null, '');

        self::assertCount(2, $rowsNull);
        self::assertCount(2, $rowsEmpty);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(string $companyId, ?string $marketplace, ?string $search): array
    {
        $qb = $this->query->listingsQueryBuilder($companyId, $marketplace, $search);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    private function seedCompany(string $companyId, string $ownerId, string $email): Company
    {
        $owner = UserBuilder::aUser()
            ->withId($ownerId)
            ->withEmail($email)
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedProduct(Company $company, string $sku, string $name): Product
    {
        $product = new Product(
            \Ramsey\Uuid\Uuid::uuid4()->toString(),
            $company,
        );
        $product->setSku($sku);
        $product->setName($name);

        $this->em->persist($product);

        return $product;
    }

    private function seedListing(
        Company $company,
        string $marketplaceSku,
        string $name,
        ?string $supplierSku,
        MarketplaceType $marketplace,
        ?Product $product = null,
    ): MarketplaceListing {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace($marketplace)
            ->withMarketplaceSku($marketplaceSku)
            ->build();

        $listing->setName($name);
        $listing->setSupplierSku($supplierSku);
        if ($product !== null) {
            $listing->setProduct($product);
        }

        $this->em->persist($listing);

        return $listing;
    }
}
