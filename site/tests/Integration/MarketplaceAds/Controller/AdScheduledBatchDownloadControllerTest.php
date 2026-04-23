<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller;

use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdLoadJobBuilder;
use App\Tests\Builders\MarketplaceAds\AdScheduledBatchBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Controller\AdScheduledBatchDownloadController}
 * (Task-11.8 UI поддержка cron-driven pipeline).
 *
 * Покрываемые инварианты:
 *  1. Корректный id своей company + файл на диске → 200 + attachment с
 *     content-disposition формата `ozon-ad-batch-<idx>-<from>_<to>.<ext>`.
 *  2. Чужая company → 404 (IDOR через `findByIdAndCompany`).
 *  3. storage_path IS NULL → 404.
 *  4. Файл отсутствует на диске → 404.
 */
final class AdScheduledBatchDownloadControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-e30000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-e30000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-e30000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-e30000000002';

    public function testDownloadReturnsFileAsAttachment(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('batch-download-ok@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);

        // Batch требует существующего AdLoadJob (FK на marketplace_ad_load_jobs).
        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->build();
        $em->persist($job);
        $em->flush();

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);

        $csvBody = "date,campaign_id,spend\n2026-04-01,camp-1,1.00";
        $relativePath = sprintf(
            'marketplace-ads/%s/%s.csv',
            self::COMPANY_ID,
            'cccccccc-cccc-cccc-cccc-cccccccccccc',
        );
        $stored = $storage->storeBytes($csvBody, $relativePath);

        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(3)
            ->withDateRange(
                new \DateTimeImmutable('2026-04-01'),
                new \DateTimeImmutable('2026-04-15'),
            )
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($stored['storagePath'], $stored['fileHash'], (int) $stored['sizeBytes'])
            ->build();
        $em->persist($batch);
        $em->flush();

        $batchId = $batch->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/batches/'.$batchId.'/download');

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('ozon-ad-batch-3-2026-04-01_2026-04-15.csv', $disposition);

        @unlink($storage->getAbsolutePath($relativePath));
    }

    public function testDownloadReturns404ForOtherCompanyBatch(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('batch-download-idor@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();
        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('batch-download-idor-other@example.test')
            ->build();
        $otherCompany = CompanyBuilder::aCompany()
            ->withId(self::OTHER_COMPANY_ID)
            ->withOwner($otherOwner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->persist($otherOwner);
        $em->persist($otherCompany);

        $foreignJob = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(2)
            ->build();
        $em->persist($foreignJob);
        $em->flush();

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);

        $relativePath = sprintf(
            'marketplace-ads/%s/%s.csv',
            self::OTHER_COMPANY_ID,
            'dddddddd-dddd-dddd-dddd-dddddddddddd',
        );
        $stored = $storage->storeBytes('foreign-body', $relativePath);

        $foreignBatch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($foreignJob->getId())
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage($stored['storagePath'], $stored['fileHash'], (int) $stored['sizeBytes'])
            ->build();
        $em->persist($foreignBatch);
        $em->flush();

        $foreignId = $foreignBatch->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/batches/'.$foreignId.'/download');

        self::assertResponseStatusCodeSame(404);

        @unlink($storage->getAbsolutePath($relativePath));
    }

    public function testDownloadReturns404WhenStoragePathIsNull(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('batch-download-nostorage@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();
        $em->persist($owner);
        $em->persist($company);

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(3)
            ->build();
        $em->persist($job);
        $em->flush();

        // Batch без storage_path — в state=IN_FLIGHT, скачивание невозможно.
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::IN_FLIGHT)
            ->withOzonUuid('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee')
            ->build();
        $em->persist($batch);
        $em->flush();

        $batchId = $batch->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/batches/'.$batchId.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadReturns404WhenFileMissingOnDisk(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('batch-download-missing-file@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();
        $em->persist($owner);
        $em->persist($company);

        $job = AdLoadJobBuilder::aJob()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(4)
            ->build();
        $em->persist($job);
        $em->flush();

        // storage_path есть, но файл не существует (ручной cleanup, LVM drop и т.п.).
        $batch = AdScheduledBatchBuilder::aBatch()
            ->withJobId($job->getId())
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(0)
            ->withState(AdScheduledBatchState::OK)
            ->withStorage(
                'marketplace-ads/'.self::COMPANY_ID.'/nonexistent-batch-file.csv',
                'deadbeef',
                100,
            )
            ->build();
        $em->persist($batch);
        $em->flush();

        $batchId = $batch->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/batches/'.$batchId.'/download');

        self::assertResponseStatusCodeSame(404);
    }
}
