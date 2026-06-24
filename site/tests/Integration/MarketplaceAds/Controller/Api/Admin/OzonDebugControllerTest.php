<?php

declare(strict_types=1);

namespace App\Tests\Integration\MarketplaceAds\Controller\Api\Admin;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Entity\OzonAdPendingReport;
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

    private const URL_ALL_CAMPAIGNS = '/debug/ozon-ads/all-campaigns';
    private const URL_LIST_REPORTS = '/debug/ozon-ads/list-reports';

    public function testAllCampaignsHappyPath(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode(['access_token' => 'TKN-campaigns'], \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode([
                'list' => [
                    [
                        'id' => '111',
                        'title' => 'Running',
                        'state' => 'CAMPAIGN_STATE_RUNNING',
                        'advObjectType' => 'SKU',
                        'createdAt' => '2026-04-01T10:00:00Z',
                    ],
                    [
                        'id' => '222',
                        'title' => 'Archived',
                        'state' => 'CAMPAIGN_STATE_ARCHIVED',
                        'advObjectType' => 'SKU',
                        'createdAt' => '2026-05-02T10:00:00Z',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR)),
        ]);

        $admin = $this->seedAdminAndCompany(withConnection: true);
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_ALL_CAMPAIGNS.'?companyId='.self::COMPANY_ID);

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertSame(self::COMPANY_ID, $data['companyId']);
        self::assertSame('test-client-id...', $data['client_id']);
        self::assertSame(2, $data['total_campaigns']);
        self::assertSame(
            ['CAMPAIGN_STATE_RUNNING' => 1, 'CAMPAIGN_STATE_ARCHIVED' => 1],
            $data['by_state'],
        );
        self::assertSame(['2026-04' => 1, '2026-05' => 1], $data['by_year_month_created']);
        self::assertSame('111', $data['campaigns'][0]['campaignId']);
        self::assertSame('Running', $data['campaigns'][0]['title']);
        self::assertSame(['list'], $data['top_level_keys']);
    }

    public function testAllCampaignsReturns400WithoutCompanyId(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $admin = $this->seedAdminAndCompany(withConnection: false);
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_ALL_CAMPAIGNS);

        self::assertResponseStatusCodeSame(400);
        self::assertSame('companyId query param required', $this->decode($client)['error']);
    }

    public function testAllCampaignsReturns404WithoutCredentials(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $admin = $this->seedAdminAndCompany(withConnection: false);
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_ALL_CAMPAIGNS.'?companyId='.self::COMPANY_ID);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('No creds', (string) $this->decode($client)['error']);
    }

    public function testListReportsHappyPathWithPendingReportProbe(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode(['access_token' => 'TKN-list'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode([
                'items' => [
                    ['UUID' => 'uuid-1', 'state' => 'OK'],
                    ['UUID' => 'uuid-pending', 'state' => 'IN_PROGRESS'],
                ],
                'total' => 2,
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode(['state' => 'IN_PROGRESS'], \JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $admin = $this->seedAdminAndCompany(withConnection: true);
        $this->persistPendingReport('uuid-pending');
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_LIST_REPORTS.'?companyId='.self::COMPANY_ID);

        self::assertResponseIsSuccessful();
        $data = $this->decode($client);
        self::assertSame(self::COMPANY_ID, $data['companyId']);
        self::assertSame('test-client-id...', $data['client_id']);
        self::assertTrue($data['token_ok']);
        self::assertSame(200, $data['token_http_code']);
        self::assertSame(2, $data['total_items_collected']);
        self::assertSame(1, $data['list_pages'][0]['page']);
        self::assertSame(2, $data['list_pages'][0]['items_count']);
        self::assertSame(2, $data['list_pages'][0]['total']);
        self::assertSame('uuid-1', $data['first_5_items'][0]['UUID']);
        self::assertSame('uuid-pending', $data['last_5_items'][1]['UUID']);
        self::assertSame('uuid-pending', $data['our_uuids'][0]['uuid']);
        self::assertTrue($data['our_uuids'][0]['found_in_list']);
        self::assertSame('IN_PROGRESS', $data['our_uuids'][0]['data']['state']);
        self::assertSame('uuid-pending', $data['direct_probes_by_uuid'][0]['uuid']);
        self::assertSame(200, $data['direct_probes_by_uuid'][0]['http_code']);
        self::assertSame('IN_PROGRESS', $data['direct_probes_by_uuid'][0]['body']['state']);
    }

    public function testListReportsReturns502WhenTokenIsMissing(): void
    {
        $client = static::createClient();
        $this->resetDb();
        $this->setHttpClient($client, [
            new MockResponse(json_encode(['error' => 'invalid credentials'], \JSON_THROW_ON_ERROR), ['http_code' => 401]),
        ]);
        $admin = $this->seedAdminAndCompany(withConnection: true);
        $this->loginAs($client, $admin);

        $client->request('GET', self::URL_LIST_REPORTS.'?companyId='.self::COMPANY_ID);

        self::assertResponseStatusCodeSame(502);
        $data = $this->decode($client);
        self::assertSame('token', $data['step']);
        self::assertSame(401, $data['http_code']);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function setHttpClient(KernelBrowser $client, array $responses): void
    {
        $i = 0;
        $callable = static function (string $method, string $url, array $options) use ($responses, &$i): ResponseInterface {
            if (!isset($responses[$i])) {
                throw new \LogicException(sprintf('MockHttpClient: no scripted response for #%d (%s %s)', $i + 1, $method, $url));
            }

            return $responses[$i++];
        };

        $mock = new MockHttpClient($callable);
        $client->getContainer()->set('http_client', $mock);
    }

    private function seedAdminAndCompany(bool $withConnection): \App\Company\Entity\User
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

        if ($withConnection) {
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
        }

        return $admin;
    }

    private function persistPendingReport(string $uuid): void
    {
        $report = new OzonAdPendingReport(
            self::COMPANY_ID,
            $uuid,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-01'),
            ['111'],
        );

        $this->em()->persist($report);
        $this->em()->flush();
    }

    private function loginAs(KernelBrowser $client, \App\Company\Entity\User $user): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', self::COMPANY_ID);
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
