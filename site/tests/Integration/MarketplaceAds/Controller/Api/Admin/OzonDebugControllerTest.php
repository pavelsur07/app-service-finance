<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api\Admin;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OzonDebugControllerTest extends WebTestCaseBase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-abcd00000001';
    private const ADMIN_ID = '22222222-2222-2222-2222-abcd00000001';
    private const OWNER_ID = '22222222-2222-2222-2222-abcd00000002';

    private const URL_TOKEN = '/api/marketplace-ads/admin/ozon/debug/token';
    private const URL_CAMPAIGNS = '/api/marketplace-ads/admin/ozon/debug/campaigns';
    private const URL_STATS_REQUEST = '/api/marketplace-ads/admin/ozon/debug/statistics/request';
    private const URL_STATS_STATUS = '/api/marketplace-ads/admin/ozon/debug/statistics/status';
    private const URL_STATS_DOWNLOAD = '/api/marketplace-ads/admin/ozon/debug/statistics/download';

    // ------------------------------------------------------------------
    // Happy-path tests with MockHttpClient swapped into the container
    // ------------------------------------------------------------------

    public function testTokenHappyPath(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode([
                'access_token' => 'ACCESS-TOKEN-123456789012345678901234567890',
                'expires_in' => 1800,
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $admin = $this->seedAdminAndCompany();
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_TOKEN.'?companyId='.self::COMPANY_ID);

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertSame(self::COMPANY_ID, $data['companyId']);
        self::assertStringStartsWith('ACCESS-TOKEN-123456', $data['access_token_prefix']);
        self::assertLessThanOrEqual(23, strlen($data['access_token_prefix']));
        self::assertStringNotContainsString('78901234567890', $data['access_token_prefix']);
        self::assertSame(1800, $data['expires_in']);
        self::assertSame(200, $data['ozon_raw_response_status']);
        self::assertSame('***REDACTED***', $data['ozon_raw_body']['access_token']);
    }

    public function testTokenRequires403WithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $owner = $this->seedCompanyOwnerOnly();
        $this->loginAs($client, $owner);

        $client->request('GET', self::URL_TOKEN.'?companyId='.self::COMPANY_ID);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCampaignsHappyPath(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode([
                'access_token' => 'TKN-short',
                'expires_in' => 1800,
            ], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'list' => [
                    ['id' => '111', 'title' => 'Running', 'state' => 'CAMPAIGN_STATE_RUNNING', 'advObjectType' => 'SKU'],
                    ['id' => '222', 'title' => 'Archived', 'state' => 'CAMPAIGN_STATE_ARCHIVED', 'advObjectType' => 'SKU'],
                    ['id' => '333', 'title' => 'Planned', 'state' => 'CAMPAIGN_STATE_PLANNED', 'advObjectType' => 'SKU'],
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $admin = $this->seedAdminAndCompany();
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_CAMPAIGNS.'?companyId='.self::COMPANY_ID);

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertSame(3, $data['total']);
        self::assertSame(
            ['CAMPAIGN_STATE_RUNNING' => 1, 'CAMPAIGN_STATE_ARCHIVED' => 1, 'CAMPAIGN_STATE_PLANNED' => 1],
            $data['states_breakdown'],
        );
        self::assertCount(3, $data['list']);
    }

    public function testCampaignsRequires403WithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $owner = $this->seedCompanyOwnerOnly();
        $this->loginAs($client, $owner);

        $client->request('GET', self::URL_CAMPAIGNS.'?companyId='.self::COMPANY_ID);

        self::assertResponseStatusCodeSame(403);
    }

    public function testStatisticsRequestHappyPath(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode([
                'access_token' => 'TKN-stats',
                'expires_in' => 1800,
            ], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(['UUID' => 'uuid-happy-1'], \JSON_THROW_ON_ERROR)),
        ]);

        $admin = $this->seedAdminAndCompany();
        $this->loginAs($client, $admin);

        $client->request(
            'POST',
            self::URL_STATS_REQUEST,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'companyId' => self::COMPANY_ID,
                'campaigns' => ['111', '222'],
                'from' => '2026-04-17',
                'to' => '2026-04-17',
                'groupBy' => 'DATE',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertSame('uuid-happy-1', $data['uuid']);
        self::assertSame(200, $data['ozon_status_code']);
        self::assertSame(['111', '222'], $data['request_body']['campaigns']);
        self::assertSame('DATE', $data['request_body']['groupBy']);
        // RFC3339 in UTC
        self::assertStringEndsWith('Z', (string) $data['request_body']['from']);
        self::assertStringEndsWith('Z', (string) $data['request_body']['to']);
    }

    public function testStatisticsRequestRequires403WithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $owner = $this->seedCompanyOwnerOnly();
        $this->loginAs($client, $owner);

        $client->request(
            'POST',
            self::URL_STATS_REQUEST,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'companyId' => self::COMPANY_ID,
                'campaigns' => ['111'],
                'from' => '2026-04-17',
                'to' => '2026-04-17',
                'groupBy' => 'DATE',
            ], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testStatisticsStatusHappyPath(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode([
                'access_token' => 'TKN-st',
                'expires_in' => 1800,
            ], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'state' => 'PROCESSING',
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $admin = $this->seedAdminAndCompany();
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_STATS_STATUS.'?companyId='.self::COMPANY_ID.'&uuid=uuid-abc');

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertSame('uuid-abc', $data['uuid']);
        self::assertSame('PROCESSING', $data['state']);
        self::assertSame(200, $data['status_code']);
    }

    public function testStatisticsStatusRequires403WithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $owner = $this->seedCompanyOwnerOnly();
        $this->loginAs($client, $owner);

        $client->request('GET', self::URL_STATS_STATUS.'?companyId='.self::COMPANY_ID.'&uuid=uuid-xyz');

        self::assertResponseStatusCodeSame(403);
    }

    public function testStatisticsDownloadHappyPath(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $csv = "date;campaign_id;campaign_name;sku;spend;views;clicks\n2026-04-17;111;Camp;SKU-1;1.00;10;2\n";
        $this->setHttpClient($client, [
            // token fetch for checkStatus
            new MockResponse(json_encode([
                'access_token' => 'TKN-dl-1',
                'expires_in' => 1800,
            ], \JSON_THROW_ON_ERROR)),
            // /statistics/{uuid}
            new MockResponse(json_encode([
                'state' => 'OK',
                'link' => '/api/client/statistics/report?UUID=uuid-dl',
            ], \JSON_THROW_ON_ERROR)),
            // token fetch for download
            new MockResponse(json_encode([
                'access_token' => 'TKN-dl-2',
                'expires_in' => 1800,
            ], \JSON_THROW_ON_ERROR)),
            // download
            new MockResponse($csv),
        ]);

        $admin = $this->seedAdminAndCompany();
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_STATS_DOWNLOAD.'?companyId='.self::COMPANY_ID.'&uuid=uuid-dl');

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertFalse($data['was_zip']);
        self::assertSame(strlen($csv), $data['size_bytes']);
        self::assertStringContainsString('campaign_id', $data['content_preview']);
        self::assertSame([], $data['files_in_zip']);
    }

    public function testStatisticsDownloadRequires403WithoutSuperAdmin(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $owner = $this->seedCompanyOwnerOnly();
        $this->loginAs($client, $owner);

        $client->request('GET', self::URL_STATS_DOWNLOAD.'?companyId='.self::COMPANY_ID.'&uuid=uuid-xyz');

        self::assertResponseStatusCodeSame(403);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @param list<MockResponse> $responses
     */
    private function setHttpClient(KernelBrowser $client, array $responses): void
    {
        $i = 0;
        $callable = function (string $method, string $url, array $options) use ($responses, &$i): ResponseInterface {
            if (!isset($responses[$i])) {
                throw new \LogicException(sprintf('MockHttpClient: no scripted response for #%d (%s %s)', $i + 1, $method, $url));
            }

            return $responses[$i++];
        };

        $mock = new MockHttpClient($callable);
        $client->getContainer()->set('http_client', $mock);
    }

    private function seedAdminAndCompany(): \App\Company\Entity\User
    {
        $em = $this->em();
        $admin = UserBuilder::aUser()
            ->withId(self::ADMIN_ID)
            ->withEmail('ozon-debug-admin@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER', 'ROLE_SUPER_ADMIN'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($admin)
            ->build();

        $em->persist($admin);
        $em->persist($company);
        $em->flush();

        $connection = new MarketplaceConnection(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $connection->setApiKey('test-secret');
        $connection->setClientId('test-client-id');
        $em->persist($connection);
        $em->flush();

        return $admin;
    }

    private function seedCompanyOwnerOnly(): \App\Company\Entity\User
    {
        $em = $this->em();
        $owner = UserBuilder::aUser()
            ->withId(self::OWNER_ID)
            ->withEmail('ozon-debug-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId(self::COMPANY_ID)
            ->withOwner($owner)
            ->build();

        $em->persist($owner);
        $em->persist($company);
        $em->flush();

        return $owner;
    }

    private function loginAs(KernelBrowser $client, \App\Company\Entity\User $user): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', self::COMPANY_ID);
        $session->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $body = (string) $client->getResponse()->getContent();
        $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
