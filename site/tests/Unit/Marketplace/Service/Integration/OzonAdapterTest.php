<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\OzonAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonAdapterTest extends TestCase
{
    /**
     * H2: операции со всех страниц должны накапливаться корректно
     * (after array_merge → array_push refactor).
     * H3: каждый HTTP-запрос должен нести явный timeout.
     */
    public function testFetchRawReportAccumulatesAllPagesAndPassesTimeout(): void
    {
        $capturedTimeouts = [];
        $responses = [
            json_encode(['result' => ['operations' => [['operation_id' => 1], ['operation_id' => 2]], 'page_count' => 2]], \JSON_THROW_ON_ERROR),
            json_encode(['result' => ['operations' => [['operation_id' => 3]], 'page_count' => 2]], \JSON_THROW_ON_ERROR),
        ];
        $index = 0;

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedTimeouts, &$index, $responses): MockResponse {
            $capturedTimeouts[] = $options['timeout'] ?? null;

            return new MockResponse($responses[$index++], ['http_code' => 200]);
        });

        $adapter = $this->createAdapter($http);
        $operations = $adapter->fetchRawReport(
            $this->company(),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );

        self::assertCount(3, $operations);
        self::assertSame([1, 2, 3], array_column($operations, 'operation_id'));

        self::assertCount(2, $capturedTimeouts);
        foreach ($capturedTimeouts as $timeout) {
            self::assertEqualsWithDelta(120, $timeout, 0.0001);
        }
    }

    private function createAdapter(MockHttpClient $http): OzonAdapter
    {
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        return new OzonAdapter($http, $repo, new NullLogger());
    }

    private function company(): Company
    {
        return $this->createMock(Company::class);
    }

    private function connection(): MarketplaceConnection
    {
        $connection = $this->createMock(MarketplaceConnection::class);
        $connection->method('getId')->willReturn('connection-id');
        $connection->method('getClientId')->willReturn('client-id');
        $connection->method('getApiKey')->willReturn('api-key');

        return $connection;
    }
}
