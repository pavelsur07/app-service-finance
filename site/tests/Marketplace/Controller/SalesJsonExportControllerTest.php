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

final class SalesJsonExportControllerTest extends WebTestCaseBase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-0000000000a1';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-0000000000b2';

    public function testReturnsJsonWithAttachmentHeader(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyA();
        $this->seedSale($wbListing, '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales/export.json');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertMatchesRegularExpression(
            '/^attachment; filename="marketplace-sales-\d{8}-\d{6}\.json"$/',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function testIncludesAllSalesForCompanyWhenNoFilters(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$ownerA, $companyA, $wbListingA] = $this->seedCompanyA();
        [$ownerB, $companyB, $wbListingB] = $this->seedCompanyB();

        $this->seedSale($wbListingA, '2026-04-01');
        $this->seedSale($wbListingA, '2026-04-15');
        $this->seedSale($wbListingA, '2026-04-30');
        $this->seedSale($wbListingB, '2026-04-10');
        $this->seedSale($wbListingB, '2026-04-20');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $ownerA, $companyA);

        $client->request('GET', '/marketplace/sales/export.json');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame(3, $payload['count']);
        self::assertCount(3, $payload['sales']);

        $listingIds = array_unique(array_column($payload['sales'], 'marketplace_sku'));
        self::assertSame(['wb-sku-A'], array_values($listingIds));
    }

    public function testFiltersByMarketplaceAndDates(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing, $ozonListing] = $this->seedCompanyA();

        $this->seedSale($wbListing, '2026-04-01');
        $this->seedSale($wbListing, '2026-04-15');
        $this->seedSale($wbListing, '2026-04-25');
        $this->seedSale($ozonListing, '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales/export.json?marketplace=wildberries&date_from=2026-04-10&date_to=2026-04-20');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame(1, $payload['count']);
        self::assertCount(1, $payload['sales']);
        self::assertSame('wildberries', $payload['sales'][0]['marketplace']);
        self::assertSame('2026-04-15', substr((string) $payload['sales'][0]['sale_date'], 0, 10));

        self::assertSame('wildberries', $payload['filters']['marketplace']);
        self::assertSame('2026-04-10', $payload['filters']['date_from']);
        self::assertSame('2026-04-20', $payload['filters']['date_to']);
    }

    public function testIgnoresArrayQueryParamsAndShowsAll(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyA();
        $this->seedSale($wbListing, '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request(
            'GET',
            '/marketplace/sales/export.json?marketplace[]=wildberries&date_from[]=2026-04-15',
        );

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);
        self::assertSame(1, $payload['count']);
        self::assertNull($payload['filters']['marketplace']);
        self::assertNull($payload['filters']['date_from']);
    }

    public function testReturnsEmptyArrayWhenNoMatches(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyA();
        $this->seedSale($wbListing, '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales/export.json?date_from=2099-01-01');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertSame(0, $payload['count']);
        self::assertSame([], $payload['sales']);
    }

    public function testPayloadStructureContainsRequiredFields(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $wbListing] = $this->seedCompanyA();
        $this->seedSale($wbListing, '2026-04-15');
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/sales/export.json');

        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);

        self::assertArrayHasKey('exported_at', $payload);
        self::assertArrayHasKey('filters', $payload);
        self::assertArrayHasKey('count', $payload);
        self::assertArrayHasKey('sales', $payload);

        self::assertSame(['marketplace', 'date_from', 'date_to'], array_keys($payload['filters']));
        self::assertNull($payload['filters']['marketplace']);
        self::assertNull($payload['filters']['date_from']);
        self::assertNull($payload['filters']['date_to']);

        self::assertNotEmpty($payload['sales']);
        $sale = $payload['sales'][0];
        foreach ([
            'id', 'sale_date', 'marketplace', 'external_order_id',
            'quantity', 'price_per_unit', 'total_revenue', 'cost_price',
            'marketplace_sku', 'listing_name',
        ] as $field) {
            self::assertArrayHasKey($field, $sale);
        }
    }

    /**
     * @return array{0: User, 1: Company, 2: MarketplaceListing, 3: MarketplaceListing}
     */
    private function seedCompanyA(): array
    {
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-0000000000a1')
            ->withEmail('export-owner-a@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_A_ID)
            ->withOwner($owner)
            ->withName('Export Company A')
            ->build();

        $wbListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-sku-A')
            ->build();

        $ozonListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withMarketplaceSku('ozon-sku-A')
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
     * @return array{0: User, 1: Company, 2: MarketplaceListing}
     */
    private function seedCompanyB(): array
    {
        $owner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-0000000000b2')
            ->withEmail('export-owner-b@example.test')
            ->build();

        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_B_ID)
            ->withOwner($owner)
            ->withName('Export Company B')
            ->build();

        $wbListing = MarketplaceListingBuilder::aListing()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('wb-sku-B')
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($wbListing);
        $em->flush();

        return [$owner, $company, $wbListing];
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

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(KernelBrowser $client): array
    {
        $body = (string) $client->getResponse()->getContent();
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
