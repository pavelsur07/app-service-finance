<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceCostExistingExternalIdsQuery;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceCostExistingExternalIdsQueryTest extends IntegrationTestCase
{
    public function testExecuteReturnsCompanyScopedExternalIdsMap(): void
    {
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000001')
            ->withEmail('marketplace-owner-1@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000001')
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        $category = new MarketplaceCostCategory(
            '33333333-3333-4333-8333-000000000001',
            $company,
            MarketplaceType::WILDBERRIES,
        );
        $category->setCode('commission');
        $category->setName('Commission');
        $this->em->persist($category);

        $costOne = new MarketplaceCost(
            '44444444-4444-4444-8444-000000000001',
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
        );
        $costOne->setExternalId('ext-1');
        $costOne->setAmount('10.00');
        $costOne->setCostDate(new \DateTimeImmutable('2024-01-01'));
        $this->em->persist($costOne);

        $costTwo = new MarketplaceCost(
            '44444444-4444-4444-8444-000000000002',
            $company,
            MarketplaceType::WILDBERRIES,
            $category,
        );
        $costTwo->setExternalId('ext-2');
        $costTwo->setAmount('20.00');
        $costTwo->setCostDate(new \DateTimeImmutable('2024-01-02'));
        $this->em->persist($costTwo);

        $otherOwner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000002')
            ->withEmail('marketplace-owner-2@example.test')
            ->build();

        $otherCompany = CompanyBuilder::aCompany()
            ->withId('11111111-1111-1111-1111-000000000002')
            ->withOwner($otherOwner)
            ->build();

        $this->em->persist($otherOwner);
        $this->em->persist($otherCompany);

        $otherCategory = new MarketplaceCostCategory(
            '33333333-3333-4333-8333-000000000002',
            $otherCompany,
            MarketplaceType::WILDBERRIES,
        );
        $otherCategory->setCode('commission');
        $otherCategory->setName('Commission');
        $this->em->persist($otherCategory);

        $otherCompanyCost = new MarketplaceCost(
            '44444444-4444-4444-8444-000000000003',
            $otherCompany,
            MarketplaceType::WILDBERRIES,
            $otherCategory,
        );
        $otherCompanyCost->setExternalId('ext-1');
        $otherCompanyCost->setAmount('30.00');
        $otherCompanyCost->setCostDate(new \DateTimeImmutable('2024-01-03'));
        $this->em->persist($otherCompanyCost);

        $this->em->flush();

        /** @var MarketplaceCostExistingExternalIdsQuery $query */
        $query = self::getContainer()->get(MarketplaceCostExistingExternalIdsQuery::class);

        $map = $query->execute($company->getId(), ['ext-1', 'ext-2', 'ext-3']);

        self::assertTrue(isset($map['ext-1']));
        self::assertTrue(isset($map['ext-2']));
        self::assertFalse(isset($map['ext-3']));

        self::assertSame([], $query->execute($company->getId(), []));
    }
}
