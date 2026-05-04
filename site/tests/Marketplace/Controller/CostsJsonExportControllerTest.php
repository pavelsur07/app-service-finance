<?php

declare(strict_types=1);

namespace App\Tests\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceCostCategory;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CostsJsonExportControllerTest extends WebTestCaseBase
{
    public function testDefaultsToOzonAndCurrentMonthWhenNoParamsAndHandlesInvalidInputs(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$owner, $company, $category] = $this->seedCompanyA();

        $now = new \DateTimeImmutable();
        $expectedDateFrom = $now->modify('first day of this month')->format('Y-m-d');
        $expectedDateTo = $now->modify('last day of this month')->format('Y-m-d');
        $inMonth = $now->modify('first day of this month')->modify('+10 days')->format('Y-m-d');
        $outsideMonth = $now->modify('first day of last month')->format('Y-m-d');

        $this->persistCost('44444444-4444-4444-8444-000000000211', $company, MarketplaceType::OZON, $category, $inMonth, null);
        $this->persistCost('44444444-4444-4444-8444-000000000212', $company, MarketplaceType::WILDBERRIES, $category, $inMonth, null);
        $this->persistCost('44444444-4444-4444-8444-000000000213', $company, MarketplaceType::OZON, $category, $outsideMonth, null);
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $owner, $company);

        $client->request('GET', '/marketplace/costs/export.json');
        self::assertResponseIsSuccessful();
        $payload = $this->decodeJson($client);
        self::assertSame('ozon', $payload['filters']['marketplace']);
        self::assertSame($expectedDateFrom, $payload['filters']['date_from']);
        self::assertSame($expectedDateTo, $payload['filters']['date_to']);
        self::assertSame(1, $payload['count']);

        $client->request('GET', '/marketplace/costs/export.json?marketplace=bad-value&date_from=not-a-date');
        self::assertResponseIsSuccessful();
        $invalidPayload = $this->decodeJson($client);
        self::assertSame('ozon', $invalidPayload['filters']['marketplace']);
        self::assertSame($expectedDateFrom, $invalidPayload['filters']['date_from']);

        $client->request('GET', '/marketplace/costs/export.json?marketplace[]=ozon&date_from[]=2026-05-01');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/marketplace/costs/export.json?category=abc');
        self::assertResponseIsSuccessful();
        $categoryInvalidPayload = $this->decodeJson($client);
        self::assertNull($categoryInvalidPayload['filters']['category']);

        $client->request('GET', '/marketplace/costs/export.json?category[]=abc');
        self::assertResponseIsSuccessful();
        $categoryArrayPayload = $this->decodeJson($client);
        self::assertNull($categoryArrayPayload['filters']['category']);
    }

    public function testJsonExportPayloadAndCompanyIsolation(): void
    {
        $client = static::createClient();
        $this->resetDb();

        [$ownerA, $companyA, $categoryA, $listingA] = $this->seedCompanyA(withListing: true);
        [$ownerB, $companyB, $categoryB] = $this->seedCompanyB();

        $this->persistCost('44444444-4444-4444-8444-000000000221', $companyA, MarketplaceType::OZON, $categoryA, '2026-05-12', $listingA);
        $this->persistCost('44444444-4444-4444-8444-000000000222', $companyA, MarketplaceType::OZON, $categoryA, '2026-05-13', null);
        $this->persistCost('44444444-4444-4444-8444-000000000223', $companyA, MarketplaceType::WILDBERRIES, $categoryA, '2026-05-13', null);
        $this->persistCost('44444444-4444-4444-8444-000000000224', $companyB, MarketplaceType::OZON, $categoryB, '2026-05-12', null);
        $this->em()->flush();

        $this->loginWithActiveCompany($client, $ownerA, $companyA);

        $client->request('GET', sprintf('/marketplace/costs/export.json?marketplace=ozon&date_from=2026-05-01&date_to=2026-05-31&category=%s&mapped=linked', $categoryA->getId()));

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('marketplace-costs-2026-05-01_2026-05-31.json', (string) $response->headers->get('Content-Disposition'));

        $payload = $this->decodeJson($client);
        self::assertSame(1, $payload['count']);
        self::assertCount(1, $payload['costs']);
        self::assertSame($payload['count'], count($payload['costs']));

        self::assertSame('ozon', $payload['filters']['marketplace']);
        self::assertSame($expectedDateFrom, $payload['filters']['date_from']);
        self::assertSame($expectedDateTo, $payload['filters']['date_to']);
        self::assertSame($categoryA->getId(), $payload['filters']['category']);
        self::assertSame('linked', $payload['filters']['mapped']);

        self::assertSame((string) $companyA->getId(), (string) $listingA->getCompany()->getId());
        self::assertSame($listingA->getId(), $payload['costs'][0]['listing_id']);
    }

    /** @return array{0: User,1: Company,2: MarketplaceCostCategory,3?: MarketplaceListing} */
    private function seedCompanyA(bool $withListing = false): array
    {
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000211')->withEmail('costs-owner-a@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-000000000211')->withOwner($owner)->build();
        $category = (new MarketplaceCostCategory('33333333-3333-4333-8333-000000000211', $company, MarketplaceType::OZON))->setCode('ads')->setName('Ads');

        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->persist($category);

        if (!$withListing) {
            $em->flush();
            return [$owner, $company, $category];
        }

        $listing = MarketplaceListingBuilder::aListing()->forCompany($company)->withMarketplace(MarketplaceType::OZON)->withMarketplaceSku('ozon-cost-sku')->build();
        $em->persist($listing);
        $em->flush();

        return [$owner, $company, $category, $listing];
    }

    /** @return array{0: User,1: Company,2: MarketplaceCostCategory} */
    private function seedCompanyB(): array
    {
        $owner = UserBuilder::aUser()->withId('22222222-2222-2222-2222-000000000212')->withEmail('costs-owner-b@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId('11111111-1111-1111-1111-000000000212')->withOwner($owner)->build();
        $category = (new MarketplaceCostCategory('33333333-3333-4333-8333-000000000212', $company, MarketplaceType::OZON))->setCode('ads')->setName('Ads');

        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->persist($category);
        $this->em()->flush();

        return [$owner, $company, $category];
    }

    private function persistCost(string $id, Company $company, MarketplaceType $marketplace, MarketplaceCostCategory $category, string $date, ?MarketplaceListing $listing): void
    {
        $cost = new MarketplaceCost($id, $company, $marketplace, $category);
        $cost->setCostDate(new \DateTimeImmutable($date));
        $cost->setAmount('150.00');
        $cost->setListing($listing);
        $this->em()->persist($cost);
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }

    /** @return array<string,mixed> */
    private function decodeJson(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
