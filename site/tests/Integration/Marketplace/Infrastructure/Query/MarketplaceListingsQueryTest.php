<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Infrastructure\Query;

use App\Company\Entity\Company;
use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceListingsQueryTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000911';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000912';

    private Company $company;
    private Company $otherCompany;
    private MarketplaceListingsQuery $query;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000911')->withEmail('listings-query@example.test')->build();
        $otherOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000912')->withEmail('listings-query-other@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(self::COMPANY_ID)->withOwner($owner)->build();
        $this->otherCompany = CompanyBuilder::aCompany()->withId(self::OTHER_COMPANY_ID)->withOwner($otherOwner)->build();

        $this->em->persist($owner);
        $this->em->persist($otherOwner);
        $this->em->persist($this->company);
        $this->em->persist($this->otherCompany);
        $this->em->flush();

        $this->query = self::getContainer()->get(MarketplaceListingsQuery::class);
    }

    public function testSearchIsCaseInsensitiveForCyrillicName(): void
    {
        $pillow = $this->listing('Наволочки 50х70 сатин 2 шт');
        $this->listing('Простыня на резинке');
        $this->em->flush();

        self::assertSame([$pillow->getId()], $this->ids(new ListingsFilter(search: 'НАВОЛ')));
    }

    public function testSearchMatchesSupplierSkuAndMarketplaceSku(): void
    {
        $bySupplierSku = $this->listing('Плед', supplierSku: 'ЦБ-00000456');
        $byMarketplaceSku = $this->listing('Плед', marketplaceSku: '6055754759');
        $this->em->flush();

        self::assertSame([$bySupplierSku->getId()], $this->ids(new ListingsFilter(search: 'цб-00000456')));
        self::assertSame([$byMarketplaceSku->getId()], $this->ids(new ListingsFilter(search: '60557547')));
    }

    public function testWildcardsInSearchAreLiteral(): void
    {
        $percent = $this->listing('Скидка 50% на плед');
        $underscore = $this->listing('Артикул A_1');
        $this->listing('Скидка 500 на плед');
        $this->listing('Артикул AB1');
        $this->em->flush();

        self::assertSame([$percent->getId()], $this->ids(new ListingsFilter(search: '50%')));
        self::assertSame([$underscore->getId()], $this->ids(new ListingsFilter(search: 'a_1')));
    }

    public function testOtherCompanyListingWithSameNameIsNotReturned(): void
    {
        $own = $this->listing('Наволочки');
        $this->listing('Наволочки', company: $this->otherCompany);
        $this->em->flush();

        $filter = new ListingsFilter(search: 'навол');

        self::assertSame([$own->getId()], $this->ids($filter));
        self::assertSame([$own->getId()], $this->query->matchingIds(self::COMPANY_ID, $filter, 100));
    }

    public function testFiltersByTagAndByAbsenceOfTags(): void
    {
        $tagged = $this->listing('Наволочки');
        $untagged = $this->listing('Наволочки');
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId(self::COMPANY_ID)->withName('Склад 1')->build();
        $this->em->persist($tag);
        $this->em->flush();
        $this->assign($tagged, $tag->getId(), self::COMPANY_ID);

        self::assertSame([$tagged->getId()], $this->ids(new ListingsFilter(tagId: $tag->getId())));
        self::assertSame([$untagged->getId()], $this->ids(new ListingsFilter(withoutTags: true)));
    }

    public function testWithoutTagsReturnsAllWhenCompanyHasNoAssignments(): void
    {
        $this->listing('Наволочки');
        $this->listing('Плед');
        $this->em->flush();

        self::assertCount(2, $this->ids(new ListingsFilter(withoutTags: true)));
    }

    public function testTagOfOtherCompanyFindsNothing(): void
    {
        $this->listing('Наволочки');
        $foreign = $this->listing('Наволочки', company: $this->otherCompany);
        $foreignTag = MarketplaceListingTagBuilder::aTag()->forCompanyId(self::OTHER_COMPANY_ID)->withName('Чужой')->build();
        $this->em->persist($foreignTag);
        $this->em->flush();
        $this->assign($foreign, $foreignTag->getId(), self::OTHER_COMPANY_ID);

        self::assertSame([], $this->ids(new ListingsFilter(tagId: $foreignTag->getId())));
    }

    public function testConditionsCombineWithAnd(): void
    {
        $match = $this->listing('Наволочки', marketplace: MarketplaceType::OZON);
        $this->listing('Наволочки', marketplace: MarketplaceType::WILDBERRIES);
        $this->listing('Плед', marketplace: MarketplaceType::OZON);
        $this->em->flush();

        $filter = new ListingsFilter(mapped: false, marketplace: MarketplaceType::OZON, search: 'навол');

        self::assertSame([$match->getId()], $this->ids($filter));
        self::assertSame([], $this->ids(new ListingsFilter(mapped: true, search: 'навол')));
    }

    public function testMatchingIdsFollowsListOrderAndRespectsLimit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->listing('Наволочки '.$i);
        }
        $this->em->flush();

        $filter = new ListingsFilter(search: 'навол');
        $all = $this->ids($filter);

        self::assertSame($all, $this->query->matchingIds(self::COMPANY_ID, $filter, 100));
        self::assertSame(array_slice($all, 0, 3), $this->query->matchingIds(self::COMPANY_ID, $filter, 3));
    }

    /**
     * @return list<string>
     */
    private function ids(ListingsFilter $filter): array
    {
        $pager = $this->query->paginate(self::COMPANY_ID, $filter, 1, 200);

        return array_values(array_map(
            static fn (MarketplaceListing $listing): string => $listing->getId(),
            iterator_to_array($pager->getCurrentPageResults()),
        ));
    }

    private function listing(
        string $name,
        ?string $supplierSku = null,
        ?string $marketplaceSku = null,
        MarketplaceType $marketplace = MarketplaceType::WILDBERRIES,
        ?Company $company = null,
    ): MarketplaceListing {
        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company ?? $this->company)
            ->withMarketplace($marketplace)
            ->withMarketplaceSku($marketplaceSku ?? 'sku-'.++$this->seq)
            ->withName($name)
            ->withSupplierSku($supplierSku)
            ->build();
        $this->em->persist($listing);

        return $listing;
    }

    private function assign(MarketplaceListing $listing, string $tagId, string $companyId): void
    {
        $this->connection->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at) VALUES (?, ?, ?, NOW())',
            [$listing->getId(), $tagId, $companyId],
        );
    }
}
