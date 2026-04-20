<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api\Admin;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\MarketplaceAds\AdRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class DownloadBronzeControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-b00000000001';
    private const ADMIN_ID = '22222222-2222-2222-2222-b00000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-b00000000002';

    /**
     * @var list<string>
     */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->createdPaths = [];
        parent::tearDown();
    }

    public function testReturnsZipFileForSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->createSuperAdmin('bronze-zip@example.test');
        $this->persistCompany($admin);

        $doc = $this->persistDocument(
            AdRawDocumentBuilder::aRawDocument()
                ->withIndex(1)
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-04-01'))
                ->build(),
        );

        $zipBytes = $this->writeStorageFile(
            sprintf('companies/%s/marketplace-ads/ozon/bronze/2026-04-01/uuid-zip.zip', self::COMPANY_ID),
            "PK\x03\x04fake-zip-bytes",
        );
        $doc->setFileStorage(
            sprintf('companies/%s/marketplace-ads/ozon/bronze/2026-04-01/uuid-zip.zip', self::COMPANY_ID),
            hash('sha256', $zipBytes),
            strlen($zipBytes),
        );
        $this->em()->flush();

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', $this->url($doc->getId()));

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('uuid-zip.zip', (string) $response->headers->get('Content-Disposition'));
        self::assertSame((string) strlen($zipBytes), $response->headers->get('Content-Length'));
    }

    public function testReturnsCsvFileForSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->createSuperAdmin('bronze-csv@example.test');
        $this->persistCompany($admin);

        $doc = $this->persistDocument(
            AdRawDocumentBuilder::aRawDocument()
                ->withIndex(2)
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-04-02'))
                ->build(),
        );

        $csvBytes = "date,campaign_id,sku,spend\n2026-04-02,1,SKU,100";
        $this->writeStorageFile(
            sprintf('companies/%s/marketplace-ads/ozon/bronze/2026-04-02/uuid-csv.csv', self::COMPANY_ID),
            $csvBytes,
        );
        $doc->setFileStorage(
            sprintf('companies/%s/marketplace-ads/ozon/bronze/2026-04-02/uuid-csv.csv', self::COMPANY_ID),
            hash('sha256', $csvBytes),
            strlen($csvBytes),
        );
        $this->em()->flush();

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', $this->url($doc->getId()));

        self::assertResponseIsSuccessful();
        $response = $client->getResponse();
        self::assertSame('text/csv', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('uuid-csv.csv', (string) $response->headers->get('Content-Disposition'));
    }

    public function testReturns403ForCompanyOwnerWithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('bronze-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $this->persistCompany($owner);

        $doc = $this->persistDocument(
            AdRawDocumentBuilder::aRawDocument()
                ->withIndex(3)
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-04-03'))
                ->build(),
        );

        $this->loginAs($client, $owner, self::COMPANY_ID);

        $client->request('GET', $this->url($doc->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testReturns404ForNonexistentDocument(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->createSuperAdmin('bronze-404-missing@example.test');
        $this->persistCompany($admin);

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', $this->url('00000000-0000-0000-0000-000000000000'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testReturns404WhenDocumentHasNoStoragePath(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->createSuperAdmin('bronze-404-nopath@example.test');
        $this->persistCompany($admin);

        // Legacy-документ: storage_path = null — бронза не сохранялась до миграции.
        $doc = $this->persistDocument(
            AdRawDocumentBuilder::aRawDocument()
                ->withIndex(4)
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-04-04'))
                ->build(),
        );

        self::assertNull($doc->getStoragePath(), 'sanity: AdRawDocument без bronze не имеет storage_path');

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', $this->url($doc->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testReturns404WhenStorageFileMissingOnDisk(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $admin = $this->createSuperAdmin('bronze-404-nofile@example.test');
        $this->persistCompany($admin);

        $doc = $this->persistDocument(
            AdRawDocumentBuilder::aRawDocument()
                ->withIndex(5)
                ->withCompanyId(self::COMPANY_ID)
                ->withMarketplace(MarketplaceType::OZON)
                ->withReportDate(new \DateTimeImmutable('2026-04-05'))
                ->build(),
        );

        // storage_path проставлен, но сам файл не создан на диске —
        // эмулируем случай, когда bronze был вычищен снаружи (ретеншн / ручная чистка).
        $doc->setFileStorage(
            sprintf('companies/%s/marketplace-ads/ozon/bronze/2026-04-05/missing.zip', self::COMPANY_ID),
            str_repeat('0', 64),
            123,
        );
        $this->em()->flush();

        $this->loginAs($client, $admin, self::COMPANY_ID);

        $client->request('GET', $this->url($doc->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    private function createSuperAdmin(string $email): object
    {
        return UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail($email)
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
    }

    private function persistCompany(object $owner): void
    {
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();
        $em = $this->em();
        $em->persist($owner);
        $em->persist($company);
        $em->flush();
    }

    private function persistDocument(AdRawDocument $doc): AdRawDocument
    {
        $em = $this->em();
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function writeStorageFile(string $relativePath, string $bytes): string
    {
        $storageRoot = (string) static::getContainer()->getParameter('app.storage_root');
        $absolute = $storageRoot.'/'.$relativePath;
        $dir = dirname($absolute);
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create test storage dir "%s"', $dir));
        }
        file_put_contents($absolute, $bytes);
        $this->createdPaths[] = $absolute;

        return $bytes;
    }

    private function url(string $documentId): string
    {
        return '/api/marketplace-ads/admin/bronze/'.$documentId.'/download';
    }

    private function loginAs($client, $user, string $companyId): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $companyId);
        $session->save();
    }
}
