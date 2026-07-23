<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAnalytics;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class UnitExtendedTagFilterControllerTest extends WebTestCaseBase
{
    private const URL = '/api/marketplace-analytics/unit-extended';
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000950';

    public function testFilterReturnsOnlyTaggedListings(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $tagged, $untagged, $tagId] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', self::URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
            'tags' => [$tagId],
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $listingIds = array_column($data['items'], 'listingId');

        self::assertContains($tagged->getId(), $listingIds);
        self::assertNotContains($untagged->getId(), $listingIds);
    }

    public function testTagSummaryReturnedOnlyWithFlag(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, , , $tagId] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        // Без флага — свод пустой.
        $client->request('GET', self::URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame([], $data['tagSummary']);

        // С флагом — есть бакет тега и бакет «Без тегов».
        $client->request('GET', self::URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
            'withTagSummary' => '1',
        ]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $tagIds = array_column($data['tagSummary'], 'tagId');
        self::assertContains($tagId, $tagIds);
        self::assertContains(null, $tagIds, 'Ожидается бакет «Без тегов»');
    }

    public function testRejectsNonUuidTag(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner] = $this->seed();
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', self::URL, [
            'marketplace' => 'ozon',
            'periodFrom' => '2026-04-01',
            'periodTo' => '2026-04-30',
            'tags' => ['not-a-uuid'],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array{0: object, 1: MarketplaceListing, 2: MarketplaceListing, 3: string}
     */
    private function seed(): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('unit-tag-filter@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Unit Tag Filter Co')
            ->build();

        $tagged = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-tagged')
            ->build();

        $untagged = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-untagged')
            ->build();

        $tag = MarketplaceListingTagBuilder::aTag()
            ->forCompanyId(self::COMPANY_ID)
            ->withName('Зима')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($tagged);
        $em->persist($untagged);
        $em->persist($tag);

        foreach ([$tagged, $untagged] as $listing) {
            $em->persist(
                MarketplaceSaleBuilder::aSale()
                    ->forCompany($company)
                    ->forListing($listing)
                    ->withMarketplace(MarketplaceType::OZON)
                    ->withSaleDate(new \DateTimeImmutable('2026-04-15'))
                    ->build(),
            );
        }

        $em->flush();

        $em->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
             VALUES (?, ?, ?, NOW())',
            [$tagged->getId(), $tag->getId(), self::COMPANY_ID],
        );

        return [$owner, $tagged, $untagged, $tag->getId()];
    }
}
