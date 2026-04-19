<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class AdLoadJobsListControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-d00000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-d00000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-d00000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-d00000000002';

    public function testReturnsJobsOrderedByCreatedAtDesc(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-jobs-own@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('ads-jobs-other@example.test')
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

        $now = new \DateTimeImmutable('2026-04-19 12:00:00');

        $olderJob = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withDateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-10'))
            ->withCreatedAt($now->modify('-2 seconds'))
            ->asCompleted()
            ->build();

        $newerJob = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->withDateRange(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-10'))
            ->withCreatedAt($now)
            ->asRunning()
            ->build();

        $otherJob = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(3)
            ->withDateRange(new \DateTimeImmutable('2026-03-01'), new \DateTimeImmutable('2026-03-10'))
            ->withCreatedAt($now->modify('-1 second'))
            ->build();

        $em->persist($olderJob);
        $em->persist($newerJob);
        $em->persist($otherJob);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/load-jobs');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('items', $data);
        self::assertCount(2, $data['items']);

        // DESC by createdAt — newer first
        self::assertSame($newerJob->getId(), $data['items'][0]['id']);
        self::assertSame($olderJob->getId(), $data['items'][1]['id']);

        // Shape of the JSON item
        self::assertSame('running', $data['items'][0]['status']);
        self::assertSame('2026-02-01', $data['items'][0]['dateFrom']);
        self::assertSame('2026-02-10', $data['items'][0]['dateTo']);
        self::assertArrayHasKey('chunksTotal', $data['items'][0]);
        self::assertArrayHasKey('createdAt', $data['items'][0]);
        self::assertArrayHasKey('finishedAt', $data['items'][0]);
        self::assertArrayHasKey('lastError', $data['items'][0]);
    }

    public function testLimitsTo20Items(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-jobs-limit@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $base = new \DateTimeImmutable('2026-04-19 12:00:00');

        for ($i = 1; $i <= 25; ++$i) {
            $job = AdLoadJobBuilder::aJob()
                ->withCompanyId(self::COMPANY_ID)
                ->withIndex($i)
                ->withMarketplace(MarketplaceType::OZON)
                ->withCreatedAt($base->modify(sprintf('-%d seconds', 25 - $i)))
                ->build();
            $em->persist($job);
        }
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/load-jobs');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(20, $data['items']);
    }

    public function testReturnsOnlyOzonJobs(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-jobs-ozon-only@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $now = new \DateTimeImmutable('2026-04-19 12:00:00');

        $ozon1 = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withMarketplace(MarketplaceType::OZON)
            ->withCreatedAt($now->modify('-2 seconds'))
            ->build();

        $ozon2 = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(2)
            ->withMarketplace(MarketplaceType::OZON)
            ->withCreatedAt($now->modify('-1 second'))
            ->build();

        $wb = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(3)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withCreatedAt($now)
            ->build();

        $em->persist($ozon1);
        $em->persist($ozon2);
        $em->persist($wb);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/load-jobs');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(2, $data['items']);

        $returnedIds = array_column($data['items'], 'id');
        self::assertContains($ozon1->getId(), $returnedIds);
        self::assertContains($ozon2->getId(), $returnedIds);
        self::assertNotContains($wb->getId(), $returnedIds);
    }
}
