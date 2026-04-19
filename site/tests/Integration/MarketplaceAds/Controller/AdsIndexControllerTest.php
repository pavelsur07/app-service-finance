<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller;

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
        $this->resetDb();
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
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertStringContainsString('Реклама', $content);
    }

    public function testReturns200WithPerformanceConnection(): void
    {
        $client = static::createClient();
        $this->resetDb();
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
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();

        $client->request('GET', '/marketplace-ads');

        self::assertResponseIsSuccessful();
    }

    public function testRedirectsWhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/marketplace-ads');

        $response = $client->getResponse();
        self::assertTrue(
            $response->isRedirect() || 401 === $response->getStatusCode() || 403 === $response->getStatusCode(),
            'Unauthenticated access must be redirected or denied, got ' . $response->getStatusCode(),
        );
    }
}
