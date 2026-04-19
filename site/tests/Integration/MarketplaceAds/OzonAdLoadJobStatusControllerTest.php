<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdLoadJob;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class OzonAdLoadJobStatusControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID       = '11111111-1111-1111-1111-b00000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-b00000000002';
    private const OWNER_ID         = '22222222-2222-2222-2222-b00000000001';

    public function testReturns200WithCorrectCounts(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('job-status@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDateRange(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-10'))
            ->withChunksTotal(2)
            ->build();
        $em->persist($job);

        $rawDoc1 = AdRawDocumentBuilder::aRawDocument()
            ->withIndex(1)
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-01-01'))
            ->asProcessed()
            ->build();

        $rawDoc2 = AdRawDocumentBuilder::aRawDocument()
            ->withIndex(2)
            ->withCompanyId(self::COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-01-02'))
            ->asFailed('test error')
            ->build();

        $em->persist($rawDoc1);
        $em->persist($rawDoc2);
        $em->flush();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/ozon/load-jobs/' . $job->getId() . '/status');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertSame($job->getId(), $data['jobId']);
        self::assertSame('pending', $data['status']);
        self::assertSame(10, $data['totalDays']);
        self::assertSame(2, $data['chunksTotal']);
        self::assertSame(0, $data['completedChunks']);
        self::assertSame(2, $data['totalDocs']);
        self::assertSame(1, $data['processedDocs']);
        self::assertSame(1, $data['failedDocs']);
        self::assertSame(0, $data['progress']);
        self::assertNull($data['lastError']);
    }

    public function testReturns404ForNonExistentJob(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('job-status-404@example.test')
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

        $client->request('GET', '/api/marketplace-ads/ozon/load-jobs/99999999-9999-9999-9999-999999999999/status');

        self::assertResponseStatusCodeSame(404);
    }

    public function testIdorReturns404ForOtherCompanysJob(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('job-status-idor@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId('22222222-2222-2222-2222-b00000000002')
            ->withEmail('job-status-idor-other@example.test')
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

        // Job belongs to otherCompany
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withMarketplace(MarketplaceType::OZON)
            ->build();
        $em->persist($job);
        $em->flush();

        // Logged in as owner of company (not otherCompany)
        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/api/marketplace-ads/ozon/load-jobs/' . $job->getId() . '/status');

        self::assertResponseStatusCodeSame(404);
    }
}
