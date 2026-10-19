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

/**
 * Поиск реестра листингов идёт на сервере по всем страницам. Раньше он
 * фильтровал только 25 строк, загруженных в браузер: на проде у компании
 * с 636 листингами «навол» находил 6 из 108.
 */
final class MarketplaceListingsSearchTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000913';

    private int $seq = 0;

    public function testSearchFindsListingBeyondFirstPage(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany();

        // 29 свежих «пледов» занимают первую страницу (25 строк), искомая
        // наволочка — самая старая, на второй странице без поиска.
        for ($i = 0; $i < 29; ++$i) {
            $this->listing($company, 'Плед '.$i, 'wb-plaid-'.$i);
        }
        $pillow = $this->listing($company, 'Наволочки 50х70 сатин 2 шт', 'wb-pillow');
        $this->em()->flush();
        $this->ageListing($pillow, '-1 day');
        $this->em()->clear();

        $this->login($client, $owner, $company);
        $crawler = $client->request('GET', '/marketplace/listings?q=НАВОЛ');

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody input.row-select');
        self::assertCount(1, $rows, 'Поиск должен вернуть ровно одну строку');
        self::assertSame($pillow->getId(), $rows->first()->attr('value'));
    }

    public function testPaginationLinksKeepSearchAndTag(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany();
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId(self::COMPANY_ID)->withName('Склад 1')->build();
        $this->em()->persist($tag);

        $listings = [];
        for ($i = 0; $i < 30; ++$i) {
            $listings[] = $this->listing($company, 'Наволочки '.$i, 'wb-pillow-'.$i);
        }
        $this->em()->flush();
        foreach ($listings as $listing) {
            $this->em()->getConnection()->executeStatement(
                'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at) VALUES (?, ?, ?, NOW())',
                [$listing->getId(), $tag->getId(), self::COMPANY_ID],
            );
        }
        $this->em()->clear();

        $this->login($client, $owner, $company);
        $crawler = $client->request('GET', '/marketplace/listings?q=навол&tag='.$tag->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(25, $crawler->filter('tbody input.row-select'));

        $secondPage = $crawler->filter('.pagination a')->reduce(
            static fn ($link): bool => str_contains((string) $link->attr('href'), 'page=2'),
        );
        self::assertGreaterThan(0, $secondPage->count(), 'Нет ссылки на вторую страницу');
        $href = urldecode((string) $secondPage->first()->attr('href'));
        self::assertStringContainsString('q=навол', $href);
        self::assertStringContainsString('tag='.$tag->getId(), $href);
    }

    public function testFiltersListingsWithoutTags(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->seedCompany();
        $tag = MarketplaceListingTagBuilder::aTag()->forCompanyId(self::COMPANY_ID)->withName('Склад 1')->build();
        $this->em()->persist($tag);
        $tagged = $this->listing($company, 'Наволочки', 'wb-tagged');
        $untagged = $this->listing($company, 'Наволочки', 'wb-untagged');
        $this->em()->flush();
        $this->em()->getConnection()->executeStatement(
            'INSERT INTO marketplace_listing_tag_assignments (listing_id, tag_id, company_id, created_at) VALUES (?, ?, ?, NOW())',
            [$tagged->getId(), $tag->getId(), self::COMPANY_ID],
        );
        $this->em()->clear();

        $this->login($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/listings?tag=none');
        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('tbody input.row-select');
        self::assertCount(1, $rows);
        self::assertSame($untagged->getId(), $rows->first()->attr('value'));

        $crawler = $client->request('GET', '/marketplace/listings?tag='.$tag->getId());
        $rows = $crawler->filter('tbody input.row-select');
        self::assertCount(1, $rows);
        self::assertSame($tagged->getId(), $rows->first()->attr('value'));
    }

    /**
     * @return array{User, Company}
     */
    private function seedCompany(): array
    {
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-000000000913')
            ->withEmail('listings-search@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Listings Search Co')
            ->build();

        $this->em()->persist($owner);
        $this->em()->persist($company);

        return [$owner, $company];
    }

    private function listing(Company $company, string $name, string $sku): MarketplaceListing
    {
        $listing = MarketplaceListingBuilder::aListing()
            ->withIndex(++$this->seq)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku($sku)
            ->withName($name)
            ->build();
        $this->em()->persist($listing);

        return $listing;
    }

    private function ageListing(MarketplaceListing $listing, string $shift): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE marketplace_listings SET created_at = ? WHERE id = ?',
            [(new \DateTimeImmutable($shift))->format('Y-m-d H:i:s'), $listing->getId()],
        );
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }
}
