<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Service\Integration;

use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Exception\MarketplaceAuthException;
use App\Marketplace\Exception\MarketplaceInvalidApiResponseException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Exception\MarketplaceTemporaryApiException;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Service\Integration\WildberriesAdapter;
use App\Tests\Builders\Company\CompanyBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class WildberriesAdapterTest extends TestCase
{
    public function testFetchRawReportReturnsEmptyListForJsonArray(): void
    {
        $adapter = $this->createAdapterWithResponse(200, '[]');

        $result = $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));

        self::assertSame([], $result);
    }

    public function testFetchRawReportThrowsRateLimitFor429(): void
    {
        $adapter = $this->createAdapterWithResponse(429, '{"error":"rate limit"}', ['retry-after' => ['120']]);


    public function testFetchRawReportParsesRetryAfterHttpDate(): void
    {
        $retryDate = (new \DateTimeImmutable('+90 seconds'))->format(DATE_RFC7231);
        $adapter = $this->createAdapterWithResponse(429, '{"error":"rate limit"}', ['retry-after' => [$retryDate]]);

        try {
            $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));
            self::fail('Expected MarketplaceRateLimitException was not thrown.');
        } catch (MarketplaceRateLimitException $e) {
            self::assertNotNull($e->getRetryAfter());
            self::assertGreaterThanOrEqual(0, $e->getRetryAfter());
            self::assertLessThanOrEqual(120, $e->getRetryAfter());
        }
    }


    public function testFetchRawReportThrowsInvalidResponseExceptionForScalarListItems(): void
    {
        $adapter = $this->createAdapterWithResponse(200, '[1,2,3]');

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));
    }

        $this->expectException(MarketplaceRateLimitException::class);
        $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));
    }

    /** @dataProvider authStatusProvider */
    public function testFetchRawReportThrowsAuthExceptionForAuthErrors(int $status): void
    {
        $adapter = $this->createAdapterWithResponse($status, '{"error":"auth"}');

        $this->expectException(MarketplaceAuthException::class);
        $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));
    }

    public function testFetchRawReportThrowsTemporaryExceptionForServerErrors(): void
    {
        $adapter = $this->createAdapterWithResponse(500, '{"error":"server"}');

        $this->expectException(MarketplaceTemporaryApiException::class);
        $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));
    }

    public function testFetchRawReportReturnsEmptyListForEmptyBody(): void
    {
        $adapter = $this->createAdapterWithResponse(200, '');

        $result = $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));

        self::assertSame([], $result);
    }

    public function testFetchRawReportThrowsInvalidResponseExceptionForInvalidJson(): void
    {
        $adapter = $this->createAdapterWithResponse(200, '{invalid');

        $this->expectException(MarketplaceInvalidApiResponseException::class);
        $adapter->fetchRawReport(CompanyBuilder::aCompany()->build(), new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-02'));
    }

    public static function authStatusProvider(): array
    {
        return [[401], [403]];
    }

    private function createAdapterWithResponse(int $statusCode, string $body, array $headers = []): WildberriesAdapter
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = (new MarketplaceConnection('22222222-2222-2222-2222-222222222222', $company, MarketplaceType::WILDBERRIES))
            ->setApiKey('test-key');

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('findByMarketplace')->willReturn($connection);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getHeaders')->with(false)->willReturn($headers);
        $response->method('getContent')->with(false)->willReturn($body);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new WildberriesAdapter($httpClient, $connectionRepository, new NullLogger());
    }
}
