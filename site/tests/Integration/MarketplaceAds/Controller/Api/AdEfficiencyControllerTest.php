<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceSale;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdDocument;
use App\MarketplaceAds\Entity\AdDocumentLine;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class AdEfficiencyControllerTest extends WebTestCaseBase
{
    private const COMPANY_A_ID = '11111111-1111-1111-1111-a00000000001';
    private const COMPANY_B_ID = '11111111-1111-1111-1111-a00000000002';
    private const OWNER_A_ID = '22222222-2222-2222-2222-a00000000001';
    private const OWNER_B_ID = '22222222-2222-2222-2222-a00000000002';

    private const LISTING_A_ID = '55555555-5555-5555-5555-a00000000001';
    private const LISTING_B_ID = '55555555-5555-5555-5555-a00000000002';

    private const URL = '/api/marketplace-ads/efficiency';

    public function testUnauthenticatedAccessIsDenied(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', self::URL.'?periodFrom=2026-04-01&periodTo=2026-04-30');

        $response = $client->getResponse();
        self::assertTrue(
            $response->isRedirect() || 401 === $response->getStatusCode() || 403 === $response->getStatusCode(),
            'Unauthenticated access must be redirected or denied, got '.$response->getStatusCode(),
        );
    }

    public function testReturns400WhenPeriodFromIsAfterPeriodTo(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedCompanyA($this->em());
        $this->loginAsCompanyA($client);

        $client->request('GET', self::URL.'?periodFrom=2026-04-30&periodTo=2026-04-01');

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('periodFrom must be <= periodTo', $data['error']);
    }

    public function testReturns400WhenPeriodFromIsNotInYmdFormat(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedCompanyA($this->em());
        $this->loginAsCompanyA($client);

        $client->request('GET', self::URL.'?periodFrom=01-04-2026&periodTo=2026-04-30');

        self::assertResponseStatusCodeSame(400);
    }

    public function testReturns400WhenMarketplaceIsInvalid(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->seedCompanyA($this->em());
        $this->loginAsCompanyA($client);

        $client->request(
            'GET',
            self::URL.'?periodFrom=2026-04-01&periodTo=2026-04-30&marketplace=not_a_marketplace',
        );

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('invalid marketplace', $data['error']);
    }

    public function testInvalidSortBySilentlyFallsBackToDefaultAndReturns200(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();
        $this->seedCompanyA($em);
        $this->seedCompanyAData($em);
        $this->loginAsCompanyA($client);

        $client->request(
            'GET',
            self::URL.'?periodFrom=2026-04-01&periodTo=2026-04-30&sortBy=evil_column&sortDir=desc',
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('total', $data);
    }

    public function testHappyPathReturnsExpectedStructure(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();
        $this->seedCompanyA($em);
        $this->seedCompanyAData($em);
        $this->loginAsCompanyA($client);

        $client->request(
            'GET',
            self::URL.'?periodFrom=2026-04-01&periodTo=2026-04-30&page=1&pageSize=25',
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('items', $data);
        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('page', $data);
        self::assertArrayHasKey('pageSize', $data);
        self::assertArrayHasKey('totals', $data);

        self::assertSame(1, $data['total']);
        self::assertSame(1, $data['page']);
        self::assertSame(25, $data['pageSize']);
        self::assertCount(1, $data['items']);

        $item = $data['items'][0];
        self::assertSame(self::LISTING_A_ID, $item['listingId']);
        self::assertSame('SKU-A', $item['sku']);
        self::assertSame('Товар A', $item['title']);
        self::assertSame('ozon', $item['marketplace']);
        self::assertEqualsWithDelta(1000.0, (float) $item['revenue'], 0.01);
        self::assertEqualsWithDelta(100.0, (float) $item['adSpend'], 0.01);
        self::assertNotNull($item['drrPercent']);
        self::assertEqualsWithDelta(10.0, (float) $item['drrPercent'], 0.01);

        self::assertArrayHasKey('revenue', $data['totals']);
        self::assertArrayHasKey('adSpend', $data['totals']);
        self::assertArrayHasKey('drrPercent', $data['totals']);
        self::assertEqualsWithDelta(1000.0, (float) $data['totals']['revenue'], 0.01);
        self::assertEqualsWithDelta(100.0, (float) $data['totals']['adSpend'], 0.01);
        self::assertEqualsWithDelta(10.0, (float) $data['totals']['drrPercent'], 0.01);
    }

    public function testIdorReturnsEmptyItemsWhenActiveCompanyDoesNotOwnData(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $this->seedCompanyA($em);
        $this->seedCompanyB($em);
        $this->seedCompanyAData($em);
        $this->seedCompanyBData($em);

        // Залогинены как owner Company B → активная компания — B; данные A не должны быть видны.
        $ownerB = $em->getRepository(\App\Company\Entity\User::class)->find(self::OWNER_B_ID);
        self::assertNotNull($ownerB);
        $client->loginUser($ownerB);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_B_ID);
        $session->save();

        $client->request(
            'GET',
            self::URL.'?periodFrom=2026-04-01&periodTo=2026-04-30',
        );

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame(1, $data['total']);
        self::assertCount(1, $data['items']);
        $ids = array_column($data['items'], 'listingId');
        self::assertContains(self::LISTING_B_ID, $ids);
        self::assertNotContains(self::LISTING_A_ID, $ids);
    }

    private function loginAsCompanyA(KernelBrowser $client): void
    {
        $em = $this->em();
        $owner = $em->getRepository(\App\Company\Entity\User::class)->find(self::OWNER_A_ID);
        self::assertNotNull($owner);
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_A_ID);
        $session->save();
    }

    private function seedCompanyA(EntityManagerInterface $em): void
    {
        $ownerA = UserBuilder::aUser()
            ->withId(self::OWNER_A_ID)
            ->withEmail('ad-eff-ctrl-a@example.test')
            ->build();
        $companyA = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_A_ID)
            ->withOwner($ownerA)
            ->build();

        $em->persist($ownerA);
        $em->persist($companyA);
        $em->flush();
    }

    private function seedCompanyB(EntityManagerInterface $em): void
    {
        $ownerB = UserBuilder::aUser()
            ->withId(self::OWNER_B_ID)
            ->withEmail('ad-eff-ctrl-b@example.test')
            ->build();
        $companyB = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_B_ID)
            ->withOwner($ownerB)
            ->build();

        $em->persist($ownerB);
        $em->persist($companyB);
        $em->flush();
    }

    private function seedCompanyAData(EntityManagerInterface $em): void
    {
        $companyA = $em->getRepository(Company::class)->find(self::COMPANY_A_ID);
        self::assertNotNull($companyA);

        $listing = $this->newListing(
            self::LISTING_A_ID,
            $companyA,
            MarketplaceType::OZON,
            'SKU-A',
            'Товар A',
        );
        $em->persist($listing);

        $this->persistSale($em, $companyA, $listing, MarketplaceType::OZON, '2026-04-10', '1000.00', 'ORD-A-1');
        $em->flush();

        $this->persistAdDocumentWithLine(
            $em,
            companyId: self::COMPANY_A_ID,
            marketplace: MarketplaceType::OZON,
            reportDate: '2026-04-10',
            campaignId: 'CAMP-A',
            parentSku: 'SKU-A',
            totalCost: '100.00',
            listingId: self::LISTING_A_ID,
            lineCost: '100.00',
        );
        $em->flush();
        $em->clear();
    }

    private function seedCompanyBData(EntityManagerInterface $em): void
    {
        $companyB = $em->getRepository(Company::class)->find(self::COMPANY_B_ID);
        self::assertNotNull($companyB);

        $listing = $this->newListing(
            self::LISTING_B_ID,
            $companyB,
            MarketplaceType::OZON,
            'SKU-B',
            'Товар B',
        );
        $em->persist($listing);

        $this->persistSale($em, $companyB, $listing, MarketplaceType::OZON, '2026-04-15', '200.00', 'ORD-B-1');
        $em->flush();
        $em->clear();
    }

    private function newListing(
        string $id,
        Company $company,
        MarketplaceType $marketplace,
        string $sku,
        string $name,
    ): MarketplaceListing {
        $listing = new MarketplaceListing($id, $company, null, $marketplace);
        $listing->setMarketplaceSku($sku);
        $listing->setSize('UNKNOWN');
        $listing->setPrice('0.00');
        $listing->setName($name);

        return $listing;
    }

    private function persistSale(
        EntityManagerInterface $em,
        Company $company,
        MarketplaceListing $listing,
        MarketplaceType $marketplace,
        string $dateYmd,
        string $totalRevenue,
        string $externalOrderId,
    ): void {
        $sale = new MarketplaceSale(
            Uuid::uuid4()->toString(),
            $company,
            $listing,
            $marketplace,
        );
        $sale->setExternalOrderId($externalOrderId);
        $sale->setSaleDate(new \DateTimeImmutable($dateYmd));
        $sale->setQuantity(1);
        $sale->setPricePerUnit($totalRevenue);
        $sale->setTotalRevenue($totalRevenue);

        $em->persist($sale);
    }

    private function persistAdDocumentWithLine(
        EntityManagerInterface $em,
        string $companyId,
        MarketplaceType $marketplace,
        string $reportDate,
        string $campaignId,
        string $parentSku,
        string $totalCost,
        string $listingId,
        string $lineCost,
    ): void {
        $adDocument = new AdDocument(
            companyId: $companyId,
            marketplace: $marketplace,
            reportDate: new \DateTimeImmutable($reportDate),
            campaignId: $campaignId,
            campaignName: $campaignId,
            parentSku: $parentSku,
            totalCost: $totalCost,
            totalImpressions: 0,
            totalClicks: 0,
            adRawDocumentId: Uuid::uuid4()->toString(),
        );
        $em->persist($adDocument);

        $line = new AdDocumentLine(
            adDocument: $adDocument,
            listingId: $listingId,
            sharePercent: '100.00',
            cost: $lineCost,
            impressions: 0,
            clicks: 0,
        );
        $em->persist($line);
    }
}
