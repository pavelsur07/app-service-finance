<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class AdRawDocumentsListControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-e00000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-e00000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-e00000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-e00000000002';

    public function testReturnsDocumentsFilteredByDateRange(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-raw-range@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $inside = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-15'))
            ->build();

        $before = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-02-28'))
            ->build();

        $after = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(3)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-04-01'))
            ->build();

        $em->persist($inside);
        $em->persist($before);
        $em->persist($after);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/raw-documents?dateFrom=2026-03-01&dateTo=2026-03-31');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('items', $data);
        self::assertCount(1, $data['items']);
        self::assertSame($inside->getId(), $data['items'][0]['id']);
        self::assertSame('2026-03-15', $data['items'][0]['reportDate']);
        self::assertArrayHasKey('status', $data['items'][0]);
        self::assertArrayHasKey('loadedAt', $data['items'][0]);
        self::assertArrayHasKey('processingError', $data['items'][0]);
    }

    public function testDefaultsToLast30DaysWhenNoDatesProvided(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-raw-default@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $today = new \DateTimeImmutable('today');

        $recent = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate($today->modify('-5 days'))
            ->build();

        $old = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate($today->modify('-60 days'))
            ->build();

        $em->persist($recent);
        $em->persist($old);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/raw-documents');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['items']);
        self::assertSame($recent->getId(), $data['items'][0]['id']);
    }

    public function testReturns400ForInvalidDateFormat(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-raw-invalid@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/raw-documents?dateFrom=2026/03/01&dateTo=2026/03/31');

        self::assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('message', $data);
    }

    public function testIdorDoesNotLeakOtherCompanyDocuments(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-raw-idor@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('ads-raw-idor-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(self::OTHER_COMPANY_ID)
            ->withOwner($otherOwner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($otherOwner);
        $em->persist($otherCompany);
        $em->flush();

        $foreignDoc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(1)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-03-05'))
            ->build();

        $em->persist($foreignDoc);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/raw-documents?dateFrom=2026-03-01&dateTo=2026-03-31');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(0, $data['items']);
    }
}
