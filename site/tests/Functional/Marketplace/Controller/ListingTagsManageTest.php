<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingTagBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ListingTagsManageTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000970';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-000000000971';

    private const PAGE_URL = '/marketplace/listings/tags';
    private const MERGE_URL = '/api/marketplace/listings/tags/merge';

    public function testPageRendersTagsWithCounts(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(2);
        $winter = $this->seedTag(self::COMPANY_ID, 'Зима');
        $this->assign($listings[0]->getId(), $winter, self::COMPANY_ID);
        $this->assign($listings[1]->getId(), $winter, self::COMPANY_ID);
        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', self::PAGE_URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Зима', (string) $client->getResponse()->getContent());
        // Счётчик листингов под тегом = 2.
        self::assertStringContainsString('2', $crawler->filter('tbody tr td.text-end')->first()->text());
    }

    public function testRenameChangesName(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $tagId = $this->seedTag(self::COMPANY_ID, 'Зма');
        $this->login($client, $owner, $company);

        $this->postJson($client, $this->renameUrl($tagId), ['name' => 'Зима']);

        self::assertResponseIsSuccessful();
        self::assertSame('Зима', $this->jsonResponse($client)['name']);
        self::assertSame('зима', $this->fetchOne(
            'SELECT slug FROM marketplace_listing_tags WHERE id = ?',
            [$tagId],
        ));
    }

    public function testRenameToExistingNameConflicts(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $this->seedTag(self::COMPANY_ID, 'Зима');
        $other = $this->seedTag(self::COMPANY_ID, 'Лето');
        $this->login($client, $owner, $company);

        $this->postJson($client, $this->renameUrl($other), ['name' => 'зима']);

        self::assertResponseStatusCodeSame(409);
        self::assertSame('tag_name_conflict', $this->jsonResponse($client)['error']['code']);
    }

    public function testRenameRejectsForeignTag(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $foreign = $this->seedTag(self::OTHER_COMPANY_ID, 'Чужой');
        $this->login($client, $owner, $company);

        $this->postJson($client, $this->renameUrl($foreign), ['name' => 'Наш']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRemovesTagAndAssignments(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(1);
        $tagId = $this->seedTag(self::COMPANY_ID, 'Мусор');
        $this->assign($listings[0]->getId(), $tagId, self::COMPANY_ID);
        $this->login($client, $owner, $company);

        $this->postJson($client, $this->deleteUrl($tagId), []);

        self::assertResponseIsSuccessful();
        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tags WHERE id = ?', [$tagId]));
        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ?', [$tagId]));
    }

    public function testDeleteRejectsForeignTag(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $foreign = $this->seedTag(self::OTHER_COMPANY_ID, 'Чужой');
        $this->login($client, $owner, $company);

        $this->postJson($client, $this->deleteUrl($foreign), []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tags WHERE id = ?', [$foreign]));
    }

    public function testMergeReassignsListingsAndDeletesSource(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company, $listings] = $this->seedCompanyWithListings(3);
        // Дубли-мусор = разные названия одного смысла (одинаковый slug в компании невозможен).
        $source = $this->seedTag(self::COMPANY_ID, 'Зимняя коллекция');
        $target = $this->seedTag(self::COMPANY_ID, 'Зима');
        // l0,l1 на источнике; l1,l2 на цели → после слияния цель = l0,l1,l2 (без дублей).
        $this->assign($listings[0]->getId(), $source, self::COMPANY_ID);
        $this->assign($listings[1]->getId(), $source, self::COMPANY_ID);
        $this->assign($listings[1]->getId(), $target, self::COMPANY_ID);
        $this->assign($listings[2]->getId(), $target, self::COMPANY_ID);
        $this->login($client, $owner, $company);

        $this->postJson($client, self::MERGE_URL, ['sourceTagId' => $source, 'targetTagId' => $target]);

        self::assertResponseIsSuccessful();
        // Источник удалён.
        self::assertSame(0, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tags WHERE id = ?', [$source]));
        // Цель несёт все три листинга, без дублей.
        self::assertSame(3, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tag_assignments WHERE tag_id = ?', [$target]));
    }

    public function testMergeIntoSelfRejected(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $tagId = $this->seedTag(self::COMPANY_ID, 'Зима');
        $this->login($client, $owner, $company);

        $this->postJson($client, self::MERGE_URL, ['sourceTagId' => $tagId, 'targetTagId' => $tagId]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testMergeRejectsForeignTag(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->seedCompanyWithListings(1);
        $mine = $this->seedTag(self::COMPANY_ID, 'Мой');
        $foreign = $this->seedTag(self::OTHER_COMPANY_ID, 'Чужой');
        $this->login($client, $owner, $company);

        $this->postJson($client, self::MERGE_URL, ['sourceTagId' => $foreign, 'targetTagId' => $mine]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, (int) $this->fetchOne('SELECT COUNT(*) FROM marketplace_listing_tags WHERE id = ?', [$foreign]));
    }

    private function renameUrl(string $tagId): string
    {
        return '/api/marketplace/listings/tags/'.$tagId.'/rename';
    }

    private function deleteUrl(string $tagId): string
    {
        return '/api/marketplace/listings/tags/'.$tagId.'/delete';
    }

    /**
     * @return array{0: User, 1: Company, 2: list<MarketplaceListing>}
     */
    private function seedCompanyWithListings(int $count): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('listing-tags-manage@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Tags Manage Co')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);

        $listings = [];
        for ($i = 1; $i <= $count; ++$i) {
            $listing = MarketplaceListingBuilder::aListing()
                ->forCompany($company)
                ->withMarketplace(MarketplaceType::WILDBERRIES)
                ->withMarketplaceSku('wb-manage-sku-'.$i)
                ->build();
            $em->persist($listing);
            $listings[] = $listing;
        }

        $em->flush();

        return [$owner, $company, $listings];
    }

    private function seedTag(string $companyId, string $name): string
    {
        $tag = MarketplaceListingTagBuilder::aTag()
            ->forCompanyId($companyId)
            ->withName($name)
            ->build();

        $this->em()->persist($tag);
        $this->em()->flush();

        return $tag->getId();
    }

    private function assign(string $listingId, string $tagId, string $companyId): void
    {
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at)
             VALUES (?, ?, ?, NOW())',
            [$listingId, $tagId, $companyId],
        );
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(KernelBrowser $client, string $url, array $body): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonResponse(KernelBrowser $client): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return $decoded;
    }

    /**
     * @param list<string> $params
     */
    private function fetchOne(string $sql, array $params): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, $params);
    }
}
