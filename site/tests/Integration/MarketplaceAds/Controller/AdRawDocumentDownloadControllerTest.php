<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\Storage\StorageService;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

/**
 * End-to-end тесты {@see \App\MarketplaceAds\Controller\AdRawDocumentDownloadController}.
 *
 * Инварианты:
 *  1. Корректный id своей company → 200 + attachment с content-disposition.
 *  2. Чужая company → 404 (IDOR-guard через findByIdAndCompany).
 *  3. storage_path IS NULL → 404.
 *  4. Файл отсутствует на диске → 404.
 */
final class AdRawDocumentDownloadControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-e20000000001';
    private const OTHER_COMPANY_ID = '11111111-1111-1111-1111-e20000000002';
    private const OWNER_ID = '22222222-2222-2222-2222-e20000000001';
    private const OTHER_OWNER_ID = '22222222-2222-2222-2222-e20000000002';

    public function testDownloadReturnsFileAsAttachment(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-download-ok@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);

        $csvBody = "date;sku;spend\n2026-04-01;SKU-1;1.00";
        $relativePath = sprintf(
            'marketplace-ads/%s/%s.csv',
            self::COMPANY_ID,
            'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        );
        $stored = $storage->storeBytes($csvBody, $relativePath);

        $doc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(1)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-04-01'))
            ->build();
        $doc->setFileStorage(
            $stored['storagePath'],
            $stored['fileHash'],
            (int) $stored['sizeBytes'],
        );

        $em->persist($doc);
        $em->flush();

        $docId = $doc->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/raw-documents/'.$docId.'/download');

        self::assertResponseIsSuccessful();

        $response = $client->getResponse();
        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('ozon-ad-2026-04-01.csv', $disposition);

        // Cleanup — storage-файл на реальном диске, удаляем, чтобы не копить мусор между запусками.
        @unlink($storage->getAbsolutePath($relativePath));
    }

    public function testDownloadReturns404ForOtherCompanyDocument(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-download-idor@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $otherOwner = UserBuilder::aUser()
            ->withId(self::OTHER_OWNER_ID)
            ->withEmail('ads-download-idor-other@example.test')
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

        /** @var StorageService $storage */
        $storage = static::getContainer()->get(StorageService::class);

        $relativePath = sprintf(
            'marketplace-ads/%s/%s.csv',
            self::OTHER_COMPANY_ID,
            'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
        );
        $stored = $storage->storeBytes('foreign-body', $relativePath);

        $foreignDoc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::OTHER_COMPANY_ID)
            ->withIndex(2)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-04-05'))
            ->build();
        $foreignDoc->setFileStorage(
            $stored['storagePath'],
            $stored['fileHash'],
            (int) $stored['sizeBytes'],
        );

        $em->persist($foreignDoc);
        $em->flush();

        $foreignId = $foreignDoc->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/raw-documents/'.$foreignId.'/download');

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
            ->withEmail('ads-download-nostorage@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        // Документ без storage_path — никогда не был сохранён на диск.
        $doc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(3)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-04-10'))
            ->build();

        $em->persist($doc);
        $em->flush();

        $docId = $doc->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/raw-documents/'.$docId.'/download');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadReturns404WhenFileMissingOnDisk(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-download-missing-file@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        // storage_path заполнен, но файла на диске нет (e.g. ручной cleanup).
        $doc = AdRawDocumentBuilder::aRawDocument()
            ->withCompanyId(self::COMPANY_ID)
            ->withIndex(4)
            ->withMarketplace(MarketplaceType::OZON)
            ->withReportDate(new \DateTimeImmutable('2026-04-11'))
            ->build();
        $doc->setFileStorage(
            'marketplace-ads/'.self::COMPANY_ID.'/nonexistent-file.csv',
            'deadbeef',
            100,
        );

        $em->persist($doc);
        $em->flush();

        $docId = $doc->getId();

        $client->loginUser($owner);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads/raw-documents/'.$docId.'/download');

        self::assertResponseStatusCodeSame(404);
    }
}
