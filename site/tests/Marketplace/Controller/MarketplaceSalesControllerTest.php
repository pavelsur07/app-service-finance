<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceSaleBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class MarketplaceSalesControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-000000000088';

    public function testLoadsIndexPageWithoutFilters(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-30');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('01.04.2026', $body);
        self::assertStringContainsString('15.04.2026', $body);
        self::assertStringContainsString('30.04.2026', $body);
    }

    public function testFiltersByDateFromAndTo(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-30');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales?date_from=2026-04-10&date_to=2026-04-20');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('15.04.2026', $body);
        self::assertStringNotContainsString('01.04.2026', $body);
        self::assertStringNotContainsString('30.04.2026', $body);
    }

    public function testIgnoresInvalidDateAndShowsAll(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-30');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales?date_from=not-a-date');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('01.04.2026', $body);
        self::assertStringContainsString('15.04.2026', $body);
        self::assertStringContainsString('30.04.2026', $body);
    }

    public function testIgnoresOutOfRangeDateAndShowsAll(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-30');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        // April has 30 days; without strict validation PHP rolls 04-31 to 05-01,
        // which would silently exclude every April sale.
        $client->request('GET', '/marketplace/sales?date_from=2026-04-31');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('01.04.2026', $body);
        self::assertStringContainsString('15.04.2026', $body);
        self::assertStringContainsString('30.04.2026', $body);
    }

    public function testIgnoresArrayDateAndShowsAll(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-30');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales?date_from[]=2026-04-15');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('01.04.2026', $body);
        self::assertStringContainsString('15.04.2026', $body);
        self::assertStringContainsString('30.04.2026', $body);
    }

    public function testIgnoresArrayQueryParamsAndShowsAll(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-30');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request(
            'GET',
            '/marketplace/sales?marketplace[]=foo&date_from[]=bar&date_to[]=baz',
        );

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('01.04.2026', $body);
        self::assertStringContainsString('15.04.2026', $body);
        self::assertStringContainsString('30.04.2026', $body);
    }

    public function testCombinesMarketplaceAndDateFilters(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing, $ozonListing] = $this->seedCompanyAndListings();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-20');
        $this->seedSale($ozonListing, '2026-04-15');
        $this->seedSale($ozonListing, '2026-04-25');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales?marketplace=wildberries&date_from=2026-04-10');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('20.04.2026', $body);
        self::assertStringNotContainsString('01.04.2026', $body);
        self::assertStringNotContainsString('15.04.2026', $body);
        self::assertStringNotContainsString('25.04.2026', $body);
    }

    public function testPaginationLinksPreserveFilters(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();
        $this->seedFiveAndAHalfPagesOfSales($wbListing);

        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request(
            'GET',
            '/marketplace/sales?marketplace=wildberries&date_from=2026-04-01&date_to=2026-04-30',
        );

        self::assertResponseIsSuccessful();

        $nextPageLinks = $crawler->filter('a[href*="page=2"]');
        self::assertGreaterThan(0, $nextPageLinks->count(), 'Pagerfanta next-page link not rendered');

        $href = (string) $nextPageLinks->first()->attr('href');
        self::assertStringContainsString('marketplace=wildberries', $href);
        self::assertStringContainsString('date_from=2026-04-01', $href);
        self::assertStringContainsString('date_to=2026-04-30', $href);
    }

    public function testPaginationLinksWithoutFiltersProduceCleanUrls(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyAndListings();
        $this->seedFiveAndAHalfPagesOfSales($wbListing);

        $this->loginWithActiveCompany($client, $owner, $company);

        $crawler = $client->request('GET', '/marketplace/sales');
        self::assertResponseIsSuccessful();

        $nextPageLinks = $crawler->filter('a[href*="page=2"]');
        self::assertGreaterThan(0, $nextPageLinks->count());

        $href = (string) $nextPageLinks->first()->attr('href');
        self::assertStringNotContainsString('marketplace=', $href);
        self::assertStringNotContainsString('date_from=', $href);
        self::assertStringNotContainsString('date_to=', $href);
    }

    /**
     * @return array{0: User, 1: Company, 2: MarketplaceListing, 3: MarketplaceListing}
     */
    private function seedCompanyAndListings(): array
    {
        $owner = UserBuilder::aUser()
            ->withEmail('marketplace-sales@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->withName('Sales Filter Co')
            ->build();

        $wbListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-sku-1')
            ->build();

        $ozonListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-sku-1')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($wbListing);
        $em->persist($ozonListing);
        $em->flush();

        return [$owner, $company, $wbListing, $ozonListing];
    }

    /**
     * Seeds 55 sales for the given listing — enough to force a second page
     * with the controller's per_page=50.
     */
    private function seedFiveAndAHalfPagesOfSales(MarketplaceListing $listing): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $day = (($i - 1) % 30) + 1;
            $this->seedSale($listing, sprintf('2026-04-%02d', $day));
        }
        $this->em()->flush();
    }

    private function seedSale(MarketplaceListing $listing, string $date): void
    {
        $sale = MarketplaceSaleBuilder::aSale()
            ->forCompany($listing->getCompany())
            ->forListing($listing)
            ->withSaleDate(new \DateTimeImmutable($date))
            ->build();

        $this->em()->persist($sale);
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }
}
