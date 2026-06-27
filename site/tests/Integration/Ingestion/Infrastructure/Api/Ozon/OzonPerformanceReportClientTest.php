<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Api\Ozon;

use App\Company\Entity\Company;
use App\Ingestion\Infrastructure\Api\Ozon\OzonPerformanceReportClient;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonPerformanceReportClientTest extends IntegrationTestCase
{
    public function testListCampaignsCachesResponseByConnectionAndTypeSet(): void
    {
        $company = $this->seedCompany('11111111-1111-4111-8111-00000000b201', 9201);
        $connection = $this->seedPerformanceConnection($company, '77777777-7777-4777-8777-00000000b201');
        $this->em->flush();

        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$calls): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_ends_with($url, '/api/client/token')) {
                return new MockResponse('{"access_token":"test-token","expires_in":1800}', ['http_code' => 200]);
            }
            if (str_contains($url, '/api/client/campaign')) {
                return new MockResponse('{"result":{"list":[{"id":"2"},{"id":"1"}]}}', ['http_code' => 200]);
            }

            throw new \LogicException(sprintf('Unexpected request: %s %s', $method, $url));
        });

        $client = $this->client($http);

        $first = $client->listCampaigns($company->getId(), $connection->getId(), ['SKU', 'SEARCH_PROMO']);
        $second = $client->listCampaigns($company->getId(), $connection->getId(), ['SEARCH_PROMO', 'SKU']);

        self::assertSame([['id' => '1'], ['id' => '2']], $first->rows);
        self::assertSame($first->rows, $second->rows);
        self::assertSame(['SEARCH_PROMO', 'SKU'], $first->metadata['advObjectTypes']);
        self::assertCount(2, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame('GET', $calls[1]['method']);
        self::assertSame(['SEARCH_PROMO', 'SKU'], $calls[1]['options']['query']['advObjectType']);
    }

    public function testDownloadReportParsesCsvWithBomSemicolonAndQuotedNewlines(): void
    {
        $company = $this->seedCompany('11111111-1111-4111-8111-00000000b202', 9202);
        $connection = $this->seedPerformanceConnection($company, '77777777-7777-4777-8777-00000000b202');
        $this->em->flush();

        $calls = [];
        $csv = "\xEF\xBB\xBFsku;title;cost\nsku-1;\"Line 1\nLine 2\";12.30\n";
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$calls, $csv): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_ends_with($url, '/api/client/token')) {
                return new MockResponse('{"access_token":"test-token","expires_in":1800}', ['http_code' => 200]);
            }
            if ('https://download.example.test/report.csv' === $url) {
                return new MockResponse($csv, ['http_code' => 200]);
            }

            throw new \LogicException(sprintf('Unexpected request: %s %s', $method, $url));
        });

        $client = $this->client($http);

        $page = $client->downloadReport($company->getId(), $connection->getId(), 'report-uuid-1', 'https://download.example.test/report.csv');

        self::assertSame([
            [
                'sku' => 'sku-1',
                'title' => "Line 1\nLine 2",
                'cost' => '12.30',
                '_ingestion_metadata' => ['reportUuid' => 'report-uuid-1'],
            ],
        ], $page->rows);
        self::assertCount(2, $calls);
        self::assertSame('https://download.example.test/report.csv', $calls[1]['url']);
        self::assertSame([], $calls[1]['options']['query'] ?? []);
    }

    private function client(MockHttpClient $http): OzonPerformanceReportClient
    {
        return new OzonPerformanceReportClient(
            $http,
            self::getContainer()->get(MarketplaceFacade::class),
            new ArrayAdapter(),
            new NullLogger(),
        );
    }

    private function seedCompany(string $companyId, int $ownerIndex): Company
    {
        $owner = UserBuilder::aUser()
            ->withIndex($ownerIndex)
            ->withEmail(sprintf('%s@example.test', str_replace('-', '', $companyId)))
            ->build();
        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function seedPerformanceConnection(Company $company, string $connectionId): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            $connectionId,
            $company,
            MarketplaceType::OZON,
            MarketplaceConnectionType::PERFORMANCE,
        );
        $connection->setApiKey('performance-secret');
        $connection->setClientId('performance-client');
        $connection->setIsActive(true);

        $this->em->persist($connection);

        return $connection;
    }
}
