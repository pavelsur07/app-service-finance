<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\Integration;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WildberriesAdapterTest extends TestCase
{
    public function testReturnsEmptyArrayForValidEmptyList(): void
    {
        $adapter = $this->createAdapter(new MockResponse('[]', ['http_code' => 200]));

        self::assertSame([], $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }

    public function testThrowsRateLimitExceptionFor429(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{"error":"too many"}', ['http_code' => 429, 'response_headers' => ['Retry-After: 15']]));

        try {
            $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceRateLimitException was not thrown');
        } catch (MarketplaceRateLimitException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertSame(15, $e->getRetryAfter());
        }
    }

    public function testThrowsAuthExceptionFor401And403(): void
    {
        $adapter401 = $this->createAdapter(new MockResponse('', ['http_code' => 401]));
        $adapter403 = $this->createAdapter(new MockResponse('', ['http_code' => 403]));

        try {
            $adapter401->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
            self::fail('Expected MarketplaceAuthException for 401 was not thrown');
        } catch (MarketplaceAuthException) {
            self::assertTrue(true);
        }

        $this->expectException(MarketplaceAuthException::class);
        $adapter403->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testThrowsTemporaryExceptionFor500(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{"error":"server"}', ['http_code' => 500]));

        $this->expectException(MarketplaceTemporaryApiException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testReturnsEmptyArrayForEmptyBodyOn200(): void
    {
        $adapter = $this->createAdapter(new MockResponse('', ['http_code' => 200]));

        self::assertSame([], $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02')));
    }


    public function testThrowsInvalidResponseExceptionForScalarListItems(): void
    {
        $adapter = $this->createAdapter(new MockResponse('[1,2,3]', ['http_code' => 200]));

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    public function testThrowsInvalidResponseExceptionForInvalidJson(): void
    {
        $adapter = $this->createAdapter(new MockResponse('{invalid', ['http_code' => 200]));

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $adapter->fetchRawReport($this->company(), new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-02'));
    }

    private function createAdapter(MockResponse $response): WildberriesAdapter
    {
        $repo = $this->createMock(MarketplaceConnectionRepository::class);
        $repo->method('findByMarketplace')->willReturn($this->connection());

        return new WildberriesAdapter(new MockHttpClient($response), $repo, new NullLogger());
    }

    private function company(): Company
    {
        return $this->createMock(Company::class);
    }

    private function connection(): MarketplaceConnection
    {
        $connection = $this->createMock(MarketplaceConnection::class);
        $connection->method('getApiKey')->willReturn('token');

        return $connection;
    }
}
