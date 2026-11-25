<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\AssignListingCategoryAction;
use App\Marketplace\Application\CreateListingCategoryAction;
use App\Marketplace\Application\DeleteListingCategoryAction;
use App\Marketplace\Application\DTO\ListingsFilter;
use App\Marketplace\Application\RenameListingCategoryAction;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\ListingCategoryInUseException;
use App\Marketplace\Exception\ListingCategoryNameConflictException;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Exception\ListingFilterTooBroadException;
use App\Marketplace\Facade\ListingCategoryFacade;
use App\Marketplace\Facade\ListingCategoryFacadeInterface;
use App\Marketplace\Infrastructure\Query\ListingCategoryAssignmentQuery;
use App\Marketplace\Infrastructure\Query\MarketplaceListingsQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ListingCategoryActionsTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000a01';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000a02';

    public function testCreateNormalizesSlugAndRejectsDuplicate(): void
    {
        $this->seedCompany(self::COMPANY_ID, 1);

        $category = $this->create()(self::COMPANY_ID, '  Платья ');
        self::assertSame('Платья', $category->getName());
        self::assertSame('платья', $category->getSlug());

        $this->expectException(ListingCategoryNameConflictException::class);
        $this->create()(self::COMPANY_ID, 'ПЛАТЬЯ');
    }

    public function testRenameConflictsOnlyWithAnotherCategory(): void
    {
        $this->seedCompany(self::COMPANY_ID, 1);
        $dresses = $this->create()(self::COMPANY_ID, 'Платья');
        $this->create()(self::COMPANY_ID, 'Юбки');

        self::assertSame('ПЛАТЬЯ', $this->rename()(self::COMPANY_ID, $dresses->getId(), 'ПЛАТЬЯ')->getName());

        $this->expectException(ListingCategoryNameConflictException::class);
        $this->rename()(self::COMPANY_ID, $dresses->getId(), 'юбки');
    }

    public function testAssignIsIdempotentAndUnassignClearsSource(): void
    {
        $ids = $this->seedCompany(self::COMPANY_ID, 3);
        $category = $this->create()(self::COMPANY_ID, 'Платья');

        $first = $this->assign()(self::COMPANY_ID, $category->getId(), $ids);
        self::assertSame(3, $first->matched);
        self::assertSame(3, $first->changed);

        $repeat = $this->assign()(self::COMPANY_ID, $category->getId(), $ids);
        self::assertSame(3, $repeat->matched);
        self::assertSame(0, $repeat->changed);
        self::assertSame(
            ['manual' => 3],
            $this->sourceCounts(self::COMPANY_ID),
        );

        $cleared = $this->assign()(self::COMPANY_ID, null, [$ids[0]]);
        self::assertSame(1, $cleared->changed);
        self::assertSame(['' => 1, 'manual' => 2], $this->sourceCounts(self::COMPANY_ID));
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM marketplace_listings WHERE (category_id IS NULL) <> (category_source IS NULL)',
            ),
        );
    }

    public function testForeignCategoryIsRejectedAndForeignListingsUntouched(): void
    {
        $ids = $this->seedCompany(self::COMPANY_ID, 1);
        $foreignIds = $this->seedCompany(self::OTHER_COMPANY_ID, 2);
        $foreignCategory = $this->create()(self::OTHER_COMPANY_ID, 'Чужая');
        $own = $this->create()(self::COMPANY_ID, 'Своя');

        try {
            $this->assign()(self::COMPANY_ID, $foreignCategory->getId(), $ids);
            self::fail('Ожидалось ListingCategoryNotFoundException');
        } catch (ListingCategoryNotFoundException) {
        }

        $result = $this->assign()(self::COMPANY_ID, $own->getId(), [...$ids, ...$foreignIds]);
        self::assertSame(1, $result->matched);
        self::assertSame(1, $result->changed);
        self::assertSame([], $this->sourceCounts(self::OTHER_COMPANY_ID, onlyAssigned: true));
    }

    public function testTooBroadFilterChangesNothing(): void
    {
        $this->seedCompany(self::COMPANY_ID, 3);
        $category = $this->create()(self::COMPANY_ID, 'Платья');

        try {
            $this->assign(maxFilterMatch: 2)(self::COMPANY_ID, $category->getId(), filter: new ListingsFilter(search: 'листинг'));
            self::fail('Ожидалось ListingFilterTooBroadException');
        } catch (ListingFilterTooBroadException $e) {
            self::assertSame(2, $e->limit);
        }

        self::assertSame([], $this->sourceCounts(self::COMPANY_ID, onlyAssigned: true));

        $result = $this->assign(maxFilterMatch: 3)(self::COMPANY_ID, $category->getId(), filter: new ListingsFilter(search: 'листинг'));
        self::assertSame(3, $result->changed);
    }

    public function testAssignRequiresExactlyOneSelection(): void
    {
        $ids = $this->seedCompany(self::COMPANY_ID, 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->assign()(self::COMPANY_ID, null, $ids, new ListingsFilter());
    }

    public function testDeleteRefusesUsedCategoryAndRemovesUnused(): void
    {
        $ids = $this->seedCompany(self::COMPANY_ID, 2);
        $used = $this->create()(self::COMPANY_ID, 'Платья');
        $unused = $this->create()(self::COMPANY_ID, 'Юбки');
        $this->assign()(self::COMPANY_ID, $used->getId(), $ids);

        try {
            $this->delete()(self::COMPANY_ID, $used->getId());
            self::fail('Ожидалось ListingCategoryInUseException');
        } catch (ListingCategoryInUseException $e) {
            self::assertSame(2, $e->listingCount);
        }

        $this->delete()(self::COMPANY_ID, $unused->getId());

        self::assertSame(
            ['Платья'],
            array_map(static fn ($c): string => $c->name, $this->facade()->list(self::COMPANY_ID)),
        );
    }

    public function testDeleteOfForeignCategoryIsNotFound(): void
    {
        $this->seedCompany(self::OTHER_COMPANY_ID, 1);
        $foreign = $this->create()(self::OTHER_COMPANY_ID, 'Чужая');

        $this->expectException(ListingCategoryNotFoundException::class);
        $this->delete()(self::COMPANY_ID, $foreign->getId());
    }

    public function testFacadeReadsOnlyOwnCompany(): void
    {
        $ids = $this->seedCompany(self::COMPANY_ID, 2);
        $foreignIds = $this->seedCompany(self::OTHER_COMPANY_ID, 1);
        $own = $this->create()(self::COMPANY_ID, 'Платья');
        $foreign = $this->create()(self::OTHER_COMPANY_ID, 'Чужая');
        $this->assign()(self::COMPANY_ID, $own->getId(), [$ids[0]]);
        $this->assign()(self::OTHER_COMPANY_ID, $foreign->getId(), $foreignIds);

        self::assertSame(
            [$ids[0] => $own->getId()],
            $this->facade()->categoriesForListings(self::COMPANY_ID, [...$ids, ...$foreignIds]),
        );
        self::assertSame([], $this->facade()->categoriesForListings(self::COMPANY_ID, []));
        self::assertSame(
            ['Платья'],
            array_map(static fn ($c): string => $c->name, $this->facade()->list(self::COMPANY_ID)),
        );
    }

    public function testBuilderBuildsCategory(): void
    {
        $category = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId(self::COMPANY_ID)->withName('Юбки')->build();

        self::assertSame('юбки', $category->getSlug());
        self::assertSame(self::COMPANY_ID, $category->getCompanyId());
    }

    private function create(): CreateListingCategoryAction
    {
        return new CreateListingCategoryAction($this->categories(), $this->em);
    }

    private function rename(): RenameListingCategoryAction
    {
        return new RenameListingCategoryAction($this->categories(), $this->em);
    }

    private function delete(): DeleteListingCategoryAction
    {
        return new DeleteListingCategoryAction($this->categories(), $this->assignments(), $this->em);
    }

    private function facade(): ListingCategoryFacadeInterface
    {
        return new ListingCategoryFacade($this->categories(), $this->assignments());
    }

    private function assign(int $maxFilterMatch = AssignListingCategoryAction::DEFAULT_MAX_FILTER_MATCH): AssignListingCategoryAction
    {
        return new AssignListingCategoryAction(
            $this->categories(),
            $this->assignments(),
            self::getContainer()->get(MarketplaceListingsQuery::class),
            $this->em,
            $maxFilterMatch,
        );
    }

    private function categories(): MarketplaceListingCategoryRepository
    {
        return new MarketplaceListingCategoryRepository($this->em);
    }

    private function assignments(): ListingCategoryAssignmentQuery
    {
        return new ListingCategoryAssignmentQuery($this->connection);
    }

    /**
     * @return array<string, int> category_source ('' — без категории) → число листингов
     */
    private function sourceCounts(string $companyId, bool $onlyAssigned = false): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT COALESCE(category_source, '') AS source, COUNT(*) FROM marketplace_listings
             WHERE company_id = :c".($onlyAssigned ? ' AND category_id IS NOT NULL' : '').'
             GROUP BY 1 ORDER BY 1',
            ['c' => $companyId],
        );

        return array_map('intval', $rows);
    }

    /**
     * @return list<string> id листингов
     */
    private function seedCompany(string $companyId, int $listings): array
    {
        $suffix = substr($companyId, -3);
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000'.$suffix)
            ->withEmail('listing-category-'.$suffix.'@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        $ids = [];
        for ($i = 1; $i <= $listings; ++$i) {
            $listing = $this->listing($company, $suffix.'-'.$i);
            $this->em->persist($listing);
            $ids[] = $listing->getId();
        }
        $this->em->flush();

        return $ids;
    }

    private function listing(Company $company, string $sku): MarketplaceListing
    {
        return MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-cat-'.$sku)
            ->withName('Листинг '.$sku)
            ->build();
    }
}
