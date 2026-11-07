<?php

declare(strict_types=1);

namespace App\Tests\Functional\MarketplaceAds\Controller;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class AdsIndexControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-c00000000001';
    private const OWNER_ID = '22222222-2222-2222-2222-c00000000001';

    public function testReturns200WithoutPerformanceConnection(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-index-no-conn@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $client->request('GET', '/marketplace-ads');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Реклама', $content);
    }

    public function testReturns200WithPerformanceConnection(): void
    {
        $client = static::createClient();
        $em = $this->em();

        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ads-index-with-conn@example.test')
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $connection->setApiKey('test-client-secret');
        $connection->setClientId('test-client-id@advertising.performance.ozon.ru');
        $em->persist($connection);
        $em->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);

        $crawler = $client->request('GET', '/marketplace-ads');

        self::assertResponseIsSuccessful();

        // Хуки assets/pages/marketplace_ads.js: адреса API на корне, адреса с id — шаблоны с плейсхолдером.
        $root = $crawler->filter('[data-marketplace-ads]');
        self::assertCount(1, $root);
        self::assertSame('/api/marketplace-ads/load-jobs', $root->attr('data-jobs-url'));
        self::assertSame('/api/marketplace-ads/raw-documents', $root->attr('data-raw-docs-url'));
        self::assertSame('/api/marketplace-ads/ozon/load-range', $root->attr('data-load-range-url'));
        $placeholder = '00000000-0000-0000-0000-000000000000';
        self::assertSame('/marketplace-ads/jobs/'.$placeholder.'/extract-batches', $root->attr('data-extract-url'));
        self::assertSame('/marketplace-ads/raw-documents/'.$placeholder.'/download', $root->attr('data-download-url'));
        self::assertSame('/api/marketplace-ads/raw-documents/'.$placeholder.'/reprocess', $root->attr('data-reprocess-url'));
        self::assertSame('/api/marketplace-ads/admin/load-jobs/'.$placeholder.'/mark-failed', $root->attr('data-mark-failed-url'));
        self::assertCount(1, $root->filter('table[data-ad-jobs][data-is-company-owner="1"] tbody'));
        self::assertCount(1, $root->filter('form[data-raw-documents-filter] input[type="date"][data-raw-date-from]'));
        self::assertCount(1, $root->filter('table[data-ad-raw-docs] tbody'));
        self::assertCount(1, $root->filter('#modal-ozon-ad-load-range[data-ozon-ad-load-range] button[data-range-submit]'));
        self::assertCount(1, $root->filter('#modal-mark-ad-load-job-failed[data-mark-failed-modal] textarea[data-mark-failed-reason]'));
        self::assertCount(1, $root->filter('#ads-confirm[data-confirm-modal]'));
        self::assertCount(0, $crawler->filter('[onclick], [onchange], [onsubmit]'));
    }

    public function testRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();

        $client->request('GET', '/marketplace-ads');

        $response = $client->getResponse();
        self::assertTrue(
            $response->isRedirect() || 401 === $response->getStatusCode() || 403 === $response->getStatusCode(),
            'Unauthenticated access must be redirected or denied, got '.$response->getStatusCode(),
        );
    }
}
