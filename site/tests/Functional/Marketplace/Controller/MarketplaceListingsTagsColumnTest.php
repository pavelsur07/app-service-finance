<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class MarketplaceListingsTagsColumnTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000903';

    public function testRendersAssignedTagChipsAndLeavesUntaggedListingEmpty(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('listing-tags-column@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Tags Column Co')
            ->build();

        $tagged = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-tagged-1')
            ->build();

        $untagged = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-untagged-1')
            ->build();

        $tag = MarketplaceListingTagBuilder::aTag()
            ->forCompanyId(self::COMPANY_ID)
            ->withName('Зимняя коллекция')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($tagged);
        $em->persist($untagged);
        $em->persist($tag);
        $em->flush();

        $em->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
             VALUES (?, ?, ?, NOW())',
            [$tagged->getId(), $tag->getId(), self::COMPANY_ID],
        );

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/marketplace/listings');

        self::assertResponseIsSuccessful();

        $chips = $crawler->filter('td.listing-tags .tag-chip');
        self::assertSame(1, $chips->count(), 'Ожидается ровно один чип тега на странице');
        self::assertStringContainsString('Зимняя коллекция', $chips->first()->text());

        $untaggedCell = $crawler->filter(sprintf('td.listing-tags[data-listing-id="%s"]', $untagged->getId()));
        self::assertSame(1, $untaggedCell->count());
        self::assertSame(0, $untaggedCell->filter('.tag-chip')->count());
    }
}
