<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceCostRepositoryFiltersTest extends IntegrationTestCase
{
    public function testFiltersByMarketplaceDateCategoryAndMappedModes(): void
    {
        [$company, $ozonCategory, $wbCategory, $listing] = $this->seedCompanyDataset();

        /** @var MarketplaceCostRepository $repository */
        $repository = self::getContainer()->get(MarketplaceCostRepository::class);

        $byMarketplace = $repository->findExportRowsByCompanyAndFilters(
            $company,
            MarketplaceType::OZON,
            null,
            null,
            null,
            'all',
        );
        self::assertCount(3, $byMarketplace);
        self::assertSame(['ozon'], array_values(array_unique(array_column($byMarketplace, 'marketplace'))));

        $byDateRange = $repository->findExportRowsByCompanyAndFilters(
            $company,
            null,
            new \DateTimeImmutable('2026-04-10'),
            new \DateTimeImmutable('2026-04-20'),
            null,
            'all',
        );
        self::assertCount(2, $byDateRange);
        self::assertSame(['2026-04-15', '2026-04-12'], array_column($byDateRange, 'cost_date'));

        $byCategory = $repository->findExportRowsByCompanyAndFilters(
            $company,
            null,
            null,
            null,
            $ozonCategory->getId(),
            'all',
        );
        self::assertCount(2, $byCategory);
        self::assertSame([$ozonCategory->getId()], array_values(array_unique(array_column($byCategory, 'category_id'))));

        $linkedOnly = $repository->findExportRowsByCompanyAndFilters(
            $company,
            null,
            null,
            null,
            null,
            'linked',
        );
        self::assertCount(2, $linkedOnly);
        self::assertNotContains(null, array_column($linkedOnly, 'listing_id'));

        $generalOnly = $repository->findExportRowsByCompanyAndFilters(
            $company,
            null,
            null,
            null,
            null,
            'general',
        );
        self::assertCount(2, $generalOnly);
        self::assertSame([null], array_values(array_unique(array_column($generalOnly, 'listing_id'))));
    }



    public function testGetByCompanyQueryBuilderAppliesCompanyMarketplaceAndDateFilters(): void
    {
        [$company] = $this->seedCompanyDataset();

        /** @var MarketplaceCostRepository $repository */
        $repository = self::getContainer()->get(MarketplaceCostRepository::class);

        $rows = $repository
            ->getByCompanyQueryBuilder(
                $company,
                MarketplaceType::OZON,
                new \DateTimeImmutable('2026-04-10'),
                new \DateTimeImmutable('2026-04-20'),
            )
            ->getQuery()
            ->getResult();

        self::assertCount(1, $rows);
        self::assertInstanceOf(MarketplaceCost::class, $rows[0]);
        self::assertSame((string) $company->getId(), (string) $rows[0]->getCompany()->getId());
        self::assertSame(MarketplaceType::OZON, $rows[0]->getMarketplace());
        self::assertSame('2026-04-15', $rows[0]->getCostDate()->format('Y-m-d'));
    }

    /**
     * @return array{0: Company, 1: MarketplaceCostCategory, 2: MarketplaceCostCategory, 3: MarketplaceListing}
     */
    private function seedCompanyDataset(): array
    {
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000121')->withEmail('mc-owner-a@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-000000000121')->withOwner($owner)->build();

        $foreignOwner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000122')->withEmail('mc-owner-b@example.test')->build();
        $foreignCompany = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-000000000122')->withOwner($foreignOwner)->build();

        $ozonCategory = (new MarketplaceCostCategory('33333333-3333-4333-8333-000000000121', $company, MarketplaceType::OZON))
            ->setCode('ozon-logistics')
            ->setName('Ozon logistics');
        $wbCategory = (new MarketplaceCostCategory('33333333-3333-4333-8333-000000000122', $company, MarketplaceType::WILDBERRIES))
            ->setCode('wb-commission')
            ->setName('WB commission');

        $listing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-linked-sku')
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($foreignOwner);
        $this->em->persist($foreignCompany);
        $this->em->persist($ozonCategory);
        $this->em->persist($wbCategory);
        $this->em->persist($listing);

        $this->persistCost('44444444-4444-4444-8444-000000000121', $company, MarketplaceType::OZON, $ozonCategory, '2026-04-01', null);
        $this->persistCost('44444444-4444-4444-8444-000000000122', $company, MarketplaceType::OZON, $ozonCategory, '2026-04-15', $listing);
        $this->persistCost('44444444-4444-4444-8444-000000000123', $company, MarketplaceType::WILDBERRIES, $wbCategory, '2026-04-25', null);
        $this->persistCost('44444444-4444-4444-8444-000000000124', $company, MarketplaceType::WILDBERRIES, $wbCategory, '2026-04-12', $listing);
        $this->persistCost('44444444-4444-4444-8444-000000000125', $foreignCompany, MarketplaceType::OZON, null, '2026-04-16', null);

        $this->em->flush();

        return [$company, $ozonCategory, $wbCategory, $listing];
    }

    private function persistCost(string $id, Company $company, MarketplaceType $marketplace, ?MarketplaceCostCategory $category, string $date, ?MarketplaceListing $listing): void
    {
        $cost = new MarketplaceCost($id, $company, $marketplace, $category);
        $cost->setAmount('100.00');
        $cost->setCostDate(new \DateTimeImmutable($date));
        $cost->setListing($listing);
        $this->em->persist($cost);
    }
}
