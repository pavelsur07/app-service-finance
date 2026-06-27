<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Infrastructure\Api\Ozon;

use App\Company\Entity\Company;
use App\Ingestion\Exception\ConnectorAuthException;
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
                $advObjectType = $options['query']['advObjectType'] ?? null;
                if ('SEARCH_PROMO' === $advObjectType) {
                    return new MockResponse('{"result":{"list":[{"id":"2","advObjectType":"SEARCH_PROMO"}]}}', ['http_code' => 200]);
                }
                if ('SKU' === $advObjectType) {
                    return new MockResponse('{"result":{"list":[{"id":"1","advObjectType":"SKU"}]}}', ['http_code' => 200]);
                }

                throw new \LogicException(sprintf('Unexpected campaign query: %s', json_encode($options['query'] ?? [])));
            }

            throw new \LogicException(sprintf('Unexpected request: %s %s', $method, $url));
        });

        $client = $this->client($http);

        $first = $client->listCampaigns($company->getId(), $connection->getId(), ['SKU', 'SEARCH_PROMO']);
        $second = $client->listCampaigns($company->getId(), $connection->getId(), ['SEARCH_PROMO', 'SKU']);

        self::assertSame([
            ['id' => '1', 'advObjectType' => 'SKU'],
            ['id' => '2', 'advObjectType' => 'SEARCH_PROMO'],
        ], $first->rows);
        self::assertSame($first->rows, $second->rows);
        self::assertSame(['SEARCH_PROMO', 'SKU'], $first->metadata['advObjectTypes']);
        self::assertCount(3, $calls);
        self::assertSame('POST', $calls[0]['method']);
        self::assertSame('GET', $calls[1]['method']);
        self::assertSame('GET', $calls[2]['method']);
        self::assertSame('SEARCH_PROMO', $calls[1]['options']['query']['advObjectType']);
        self::assertSame('SKU', $calls[2]['options']['query']['advObjectType']);
    }

    public function testRejectsMismatchedConnectionRefInsteadOfFallingBackToCompanyCredentials(): void
    {
        $company = $this->seedCompany('11111111-1111-4111-8111-00000000b203', 9203);
        $this->seedPerformanceConnection(
            $company,
            '77777777-7777-4777-8777-00000000b203',
            'performance-client',
            'performance-secret',
        );
        $sellerConnection = $this->seedConnection(
            $company,
            '77777777-7777-4777-8777-00000000b204',
            MarketplaceConnectionType::SELLER,
            'seller-client',
            'seller-secret',
        );
        $this->em->flush();

        $calls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$calls): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_ends_with($url, '/api/client/token')) {
                return new MockResponse('{"access_token":"second-token","expires_in":1800}', ['http_code' => 200]);
            }
            if (str_contains($url, '/api/client/campaign')) {
                return new MockResponse('{"result":{"list":[{"id":"1"}]}}', ['http_code' => 200]);
            }

            throw new \LogicException(sprintf('Unexpected request: %s %s', $method, $url));
        });

        $client = $this->client($http);

        $this->expectException(ConnectorAuthException::class);
        $this->expectExceptionMessage('Ozon Performance credentials were not found.');

        try {
            $client->listCampaigns($company->getId(), $sellerConnection->getId(), ['SKU']);
        } finally {
            self::assertSame([], $calls);
        }
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
        self::assertStringNotContainsString('Authorization:', implode("\n", $this->headerLines($calls[1]['options'])));
    }

    public function testDownloadReportNormalizesRelativeOzonLinkAndKeepsBearerAuth(): void
    {
        $company = $this->seedCompany('11111111-1111-4111-8111-00000000b205', 9205);
        $connection = $this->seedPerformanceConnection($company, '77777777-7777-4777-8777-00000000b205');
        $this->em->flush();

        $calls = [];
        $csv = "sku,cost\nsku-2,45.60\n";
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$calls, $csv): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_ends_with($url, '/api/client/token')) {
                return new MockResponse('{"access_token":"relative-token","expires_in":1800}', ['http_code' => 200]);
            }
            if ('https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid-2' === $url) {
                return new MockResponse($csv, ['http_code' => 200]);
            }

            throw new \LogicException(sprintf('Unexpected request: %s %s', $method, $url));
        });

        $page = $this->client($http)->downloadReport(
            $company->getId(),
            $connection->getId(),
            'report-uuid-2',
            '/api/client/statistics/report?UUID=report-uuid-2',
        );

        self::assertSame('https://api-performance.ozon.ru/api/client/statistics/report?UUID=report-uuid-2', $calls[1]['url']);
        self::assertStringContainsString('Authorization: Bearer relative-token', implode("\n", $this->headerLines($calls[1]['options'])));
        self::assertSame('sku-2', $page->rows[0]['sku'] ?? null);
    }

    public function testDownloadReportExtractsCsvFromZipPayload(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required for this test.');
        }

        $company = $this->seedCompany('11111111-1111-4111-8111-00000000b206', 9206);
        $connection = $this->seedPerformanceConnection($company, '77777777-7777-4777-8777-00000000b206');
        $this->em->flush();

        $calls = [];
        $zip = $this->zipWithCsv("sku;cost\nsku-zip;78.90\n");
        $http = new MockHttpClient(static function (string $method, string $url, array $options = []) use (&$calls, $zip): MockResponse {
            $calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_ends_with($url, '/api/client/token')) {
                return new MockResponse('{"access_token":"zip-token","expires_in":1800}', ['http_code' => 200]);
            }
            if ('https://download.example.test/report.zip' === $url) {
                return new MockResponse($zip, ['http_code' => 200]);
            }

            throw new \LogicException(sprintf('Unexpected request: %s %s', $method, $url));
        });

        $page = $this->client($http)->downloadReport(
            $company->getId(),
            $connection->getId(),
            'report-uuid-zip',
            'https://download.example.test/report.zip',
        );

        self::assertSame([
            [
                'sku' => 'sku-zip',
                'cost' => '78.90',
                '_ingestion_metadata' => ['reportUuid' => 'report-uuid-zip'],
            ],
        ], $page->rows);
    }

    private function client(MockHttpClient $http): OzonPerformanceReportClient
    {
        return new OzonPerformanceReportClient(
            $http,
            self::getContainer()->get(MarketplaceFacade::class),
            new ArrayAdapter(),
            new NullLogger(),
            'https://api-performance.ozon.ru',
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

    private function seedPerformanceConnection(
        Company $company,
        string $connectionId,
        string $clientId = 'performance-client',
        string $apiKey = 'performance-secret',
    ): MarketplaceConnection {
        return $this->seedConnection($company, $connectionId, MarketplaceConnectionType::PERFORMANCE, $clientId, $apiKey);
    }

    private function seedConnection(
        Company $company,
        string $connectionId,
        MarketplaceConnectionType $connectionType,
        string $clientId,
        string $apiKey,
    ): MarketplaceConnection {
        $connection = new MarketplaceConnection(
            $connectionId,
            $company,
            MarketplaceType::OZON,
            $connectionType,
        );
        $connection->setApiKey($apiKey);
        $connection->setClientId($clientId);
        $connection->setIsActive(true);

        $this->em->persist($connection);

        return $connection;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function headerLines(array $options): array
    {
        $headers = $options['headers'] ?? [];
        if (!is_array($headers)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $header): string => is_scalar($header) ? (string) $header : '',
            $headers,
        ));
    }

    private function zipWithCsv(string $csv): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ozon_perf_zip_test_');
        self::assertIsString($file);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('report.csv', $csv));
        self::assertTrue($zip->close());

        $content = file_get_contents($file);
        @unlink($file);

        self::assertIsString($content);

        return $content;
    }
}
