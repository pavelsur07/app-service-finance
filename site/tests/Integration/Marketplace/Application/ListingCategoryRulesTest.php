<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\DeleteListingCategoryAction;
use App\Marketplace\Application\SaveListingCategoryRuleAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\ListingCategoryNotFoundException;
use App\Marketplace\Infrastructure\Query\ListingCategoryAssignmentQuery;
use App\Marketplace\Infrastructure\Query\ListingCategoryRuleQuery;
use App\Marketplace\Repository\MarketplaceListingCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\ListingCategoryRuleBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class ListingCategoryRulesTest extends IntegrationTestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000b01';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000b02';
    private const DRESSES_OZON = 'ozon:17028922:93080';
    private const DRESSES_WB = 'wb:69';

    public function testRuleAssignsAllListingsWithKeyExceptManualAndForeign(): void
    {
        $company = $this->company(self::COMPANY_ID);
        $other = $this->company(self::OTHER_COMPANY_ID);
        $dresses = $this->category(self::COMPANY_ID, 'Платья');
        $skirts = $this->category(self::COMPANY_ID, 'Юбки');
        $a = $this->listing($company, 'a', self::DRESSES_OZON);
        $b = $this->listing($company, 'b', self::DRESSES_OZON);
        $manual = $this->listing($company, 'm', self::DRESSES_OZON);
        $otherKey = $this->listing($company, 'o', 'ozon:1:2');
        $foreign = $this->listing($other, 'f', self::DRESSES_OZON);
        $this->em->flush();
        $this->connection->executeStatement(
            "UPDATE marketplace_listings SET category_id = ?, category_source = 'manual' WHERE id = ?",
            [$skirts, $manual],
        );

        self::assertSame(2, $this->save()(self::COMPANY_ID, self::DRESSES_OZON, $dresses));
        self::assertSame(
            [$a => $dresses.'/rule', $b => $dresses.'/rule', $manual => $skirts.'/manual', $otherKey => '/', $foreign => '/'],
            $this->categoryState([$a, $b, $manual, $otherKey, $foreign]),
        );

        // Повтор — ничего не пишет, правило одно.
        self::assertSame(0, $this->save()(self::COMPANY_ID, self::DRESSES_OZON, $dresses));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_listing_category_rules WHERE company_id = ?', [self::COMPANY_ID]));
    }

    public function testReplacingRuleMovesRuleListingsButNotManual(): void
    {
        $company = $this->company(self::COMPANY_ID);
        $dresses = $this->category(self::COMPANY_ID, 'Платья');
        $skirts = $this->category(self::COMPANY_ID, 'Юбки');
        $a = $this->listing($company, 'a', self::DRESSES_WB, MarketplaceType::WILDBERRIES);
        $manual = $this->listing($company, 'm', self::DRESSES_WB, MarketplaceType::WILDBERRIES);
        $this->em->flush();
        $this->connection->executeStatement(
            "UPDATE marketplace_listings SET category_id = ?, category_source = 'manual' WHERE id = ?",
            [$dresses, $manual],
        );

        $this->save()(self::COMPANY_ID, self::DRESSES_WB, $dresses);
        self::assertSame(1, $this->save()(self::COMPANY_ID, self::DRESSES_WB, $skirts));
        self::assertSame([$a => $skirts.'/rule', $manual => $dresses.'/manual'], $this->categoryState([$a, $manual]));
    }

    public function testUnmatchedKeysAndRulesOverview(): void
    {
        $company = $this->company(self::COMPANY_ID);
        $dresses = $this->category(self::COMPANY_ID, 'Платья');
        $this->listing($company, 'a', self::DRESSES_WB, MarketplaceType::WILDBERRIES, 'Платья');
        $this->listing($company, 'b', self::DRESSES_WB, MarketplaceType::WILDBERRIES, 'Платья');
        $this->listing($company, 'c', self::DRESSES_OZON);
        $this->listing($company, 'n', null);
        $this->em->flush();

        $unmatched = $this->rules()->unmatched(self::COMPANY_ID);
        self::assertSame([self::DRESSES_WB, self::DRESSES_OZON], array_column($unmatched, 'sourceKey'));
        self::assertSame(['wildberries', 'ozon'], array_column($unmatched, 'marketplace'));
        self::assertSame(['Платья', null], array_column($unmatched, 'subjectName'));
        self::assertSame([2, 1], array_column($unmatched, 'listingsCount'));
        self::assertCount(2, $unmatched[0]['examples']);

        $this->save()(self::COMPANY_ID, self::DRESSES_WB, $dresses);
        self::assertSame([self::DRESSES_OZON], array_column($this->rules()->unmatched(self::COMPANY_ID), 'sourceKey'));
        $rules = $this->rules()->rules(self::COMPANY_ID);
        self::assertSame([[
            'sourceKey' => self::DRESSES_WB, 'marketplace' => 'wildberries', 'subjectName' => 'Платья',
            'categoryId' => $dresses, 'categoryName' => 'Платья', 'listingsCount' => 2,
        ]], $rules);
    }

    public function testDeletingUnusedCategoryDeletesItsRules(): void
    {
        $this->company(self::COMPANY_ID);
        $dresses = $this->category(self::COMPANY_ID, 'Платья');
        $this->save()(self::COMPANY_ID, self::DRESSES_OZON, $dresses);

        (new DeleteListingCategoryAction($this->categories(), new ListingCategoryAssignmentQuery($this->connection), $this->em))(self::COMPANY_ID, $dresses);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_listing_category_rules'));
    }

    public function testRejectsBadKeyAndForeignCategory(): void
    {
        $this->company(self::COMPANY_ID);
        $this->company(self::OTHER_COMPANY_ID);
        $foreign = $this->category(self::OTHER_COMPANY_ID, 'Чужая');

        try {
            $this->save()(self::COMPANY_ID, 'ozon:abc', $foreign);
            self::fail('Ключ должен быть отклонён');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(ListingCategoryNotFoundException::class);
        $this->save()(self::COMPANY_ID, self::DRESSES_OZON, $foreign);
    }

    public function testApplyAfterCatalogLoadAssignsNewListings(): void
    {
        $company = $this->company(self::COMPANY_ID);
        $dresses = $this->category(self::COMPANY_ID, 'Платья');
        $this->save()(self::COMPANY_ID, self::DRESSES_OZON, $dresses);
        $new = $this->listing($company, 'new', self::DRESSES_OZON);
        $this->em->flush();

        self::assertSame(1, $this->rules()->apply(self::COMPANY_ID));
        self::assertSame([$new => $dresses.'/rule'], $this->categoryState([$new]));
    }

    public function testBuilderBuildsRule(): void
    {
        $category = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId(self::COMPANY_ID)->build();
        $rule = ListingCategoryRuleBuilder::aRule()->forCategory($category)->withSourceKey(self::DRESSES_WB)->build();

        self::assertSame(self::COMPANY_ID, $rule->getCompanyId());
        self::assertSame(self::DRESSES_WB, $rule->getSourceKey());
        self::assertSame($category, $rule->getCategory());
    }

    private function save(): SaveListingCategoryRuleAction
    {
        return new SaveListingCategoryRuleAction($this->categories(), $this->rules(), $this->em);
    }

    private function rules(): ListingCategoryRuleQuery
    {
        return new ListingCategoryRuleQuery($this->connection);
    }

    private function categories(): MarketplaceListingCategoryRepository
    {
        return new MarketplaceListingCategoryRepository($this->em);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> id → "categoryId/source"
     */
    private function categoryState(array $ids): array
    {
        $state = [];
        foreach ($ids as $id) {
            $row = $this->connection->fetchAssociative('SELECT category_id, category_source FROM marketplace_listings WHERE id = ?', [$id]);
            self::assertIsArray($row);
            $state[$id] = $row['category_id'].'/'.$row['category_source'];
        }

        return $state;
    }

    private function company(string $companyId): Company
    {
        $suffix = substr($companyId, -3);
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000'.$suffix)->withEmail('listing-rule-'.$suffix.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function category(string $companyId, string $name): string
    {
        $category = MarketplaceListingCategoryBuilder::aCategory()->forCompanyId($companyId)->withName($name)->build();
        $this->em->persist($category);
        $this->em->flush();

        return $category->getId();
    }

    private function listing(Company $company, string $sku, ?string $key, MarketplaceType $type = MarketplaceType::OZON, ?string $subject = null): string
    {
        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace($type)
            ->withMarketplaceSku('rule-'.substr((string) $company->getId(), -3).'-'.$sku)->withName('Листинг '.$sku)->build();
        $listing->setMarketplaceData(array_filter(['category_key' => $key, 'subject_name' => $subject]));
        $this->em->persist($listing);

        return $listing->getId();
    }
}
