<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Infrastructure\Api\Ozon\OzonTransactionTotalsClient;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonTransactionTotalsClientTest extends TestCase
{
    /**
     * M2: HTTP-ошибку нельзя глотать через toArray(false) — статус должен
     * всплыть в исключении, а не парситься как валидные данные.
     */
    public function testThrowsAndSurfacesHttpStatusOnErrorResponse(): void
    {
        $dbal = $this->createMock(Connection::class);
        $dbal->method('fetchAssociative')->willReturn(['api_key' => 'key', 'client_id' => 'client']);
        $credentialsQuery = new MarketplaceCredentialsQuery($dbal);

        $http = new MockHttpClient(new MockResponse('{"error":"boom"}', ['http_code' => 500]));
        $client = new OzonTransactionTotalsClient($http, $credentialsQuery);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $client->fetchTotals(
            '0192f0c2-0000-7000-8000-000000000000',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-31'),
        );
    }
}
