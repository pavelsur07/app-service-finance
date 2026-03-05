<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\MarketplaceListingPreloadQuery;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class MarketplaceListingPreloadQueryTest extends IntegrationTestCase
{
    public function testFetchBySkusReturnsMatchingRows(): void
    {
        $user = (new User('11111111-1111-4111-8111-111111111111'))
            ->setEmail('integration-preload@example.com')
            ->setPassword('secret');

        $company = (new Company('22222222-2222-4222-8222-222222222222', $user))
            ->setName('Integration Company');

        $listingA = (new MarketplaceListing(
            '33333333-3333-4333-8333-333333333333',
            $company,
            null,
            MarketplaceType::WILDBERRIES
        ))
            ->setMarketplaceSku('SKU-1')
            ->setSize(null)
            ->setPrice('100.00');

        $listingB = (new MarketplaceListing(
            '44444444-4444-4444-8444-444444444444',
            $company,
            null,
            MarketplaceType::WILDBERRIES
        ))
            ->setMarketplaceSku('SKU-2')
            ->setSize('XL')
            ->setPrice('200.00');

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($listingA);
        $this->em->persist($listingB);
        $this->em->flush();

        /** @var MarketplaceListingPreloadQuery $query */
        $query = self::getContainer()->get(MarketplaceListingPreloadQuery::class);

        $rows = $query->fetchBySkus(
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            [' SKU-1 ', '', 'SKU-2', 'SKU-1', 'MISSING']
        );

        self::assertCount(2, $rows);

        $bySku = [];
        foreach ($rows as $row) {
            $bySku[$row['marketplace_sku']] = $row;
        }

        self::assertArrayHasKey('SKU-1', $bySku);
        self::assertSame('33333333-3333-4333-8333-333333333333', $bySku['SKU-1']['id']);
        self::assertSame('UNKNOWN', $bySku['SKU-1']['size']);

        self::assertArrayHasKey('SKU-2', $bySku);
        self::assertSame('44444444-4444-4444-8444-444444444444', $bySku['SKU-2']['id']);
        self::assertSame('XL', $bySku['SKU-2']['size']);
    }

    public function testFetchBySkusReturnsEmptyArrayForEmptyInput(): void
    {
        /** @var MarketplaceListingPreloadQuery $query */
        $query = self::getContainer()->get(MarketplaceListingPreloadQuery::class);

        self::assertSame([], $query->fetchBySkus('company', MarketplaceType::WILDBERRIES->value, []));
        self::assertSame([], $query->fetchBySkus('company', MarketplaceType::WILDBERRIES->value, [' ', "\t", '']));
    }
}
