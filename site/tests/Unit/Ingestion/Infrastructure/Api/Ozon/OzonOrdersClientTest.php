<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Infrastructure\Api\Ozon\OzonCredentialProviderInterface;
use App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonOrdersClientTest extends TestCase
{
    /**
     * Регрессия: конструктор ConnectorRateLimitedException требует
     * retryAfterSeconds, а вызывался с одним аргументом. На старом коде 429 от
     * Ozon давал ArgumentCountError вместо отложенного ре-диспатча — то есть
     * ровно там, где ретрай и нужен, загрузка падала насмерть.
     */
    public function testRateLimitCarriesRetryAfterFromHeader(): void
    {
        $client = $this->client(new MockResponse('{"error":"rate"}', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => '45'],
        ]));

        try {
            $this->fetch($client);
            self::fail('Rate limit must throw ConnectorRateLimitedException.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(45, $exception->retryAfterSeconds());
        }
    }

    public function testRateLimitWithoutHeaderFallsBackToDefault(): void
    {
        $client = $this->client(new MockResponse('{"error":"rate"}', ['http_code' => 429]));

        try {
            $this->fetch($client);
            self::fail('Rate limit must throw ConnectorRateLimitedException.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds());
        }
    }

    /**
     * Нечисловой Retry-After (HTTP-дата) не должен давать нулевую задержку:
     * ре-диспатч без паузы упёрся бы в тот же лимит немедленно.
     */
    #[DataProvider('unusableRetryAfterProvider')]
    public function testUnusableRetryAfterFallsBackToDefault(string $header): void
    {
        $client = $this->client(new MockResponse('{"error":"rate"}', [
            'http_code' => 429,
            'response_headers' => ['retry-after' => $header],
        ]));

        try {
            $this->fetch($client);
            self::fail('Rate limit must throw ConnectorRateLimitedException.');
        } catch (ConnectorRateLimitedException $exception) {
            self::assertSame(60, $exception->retryAfterSeconds());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableRetryAfterProvider(): iterable
    {
        yield 'http date' => ['Wed, 01 Sep 2026 12:00:00 GMT'];
        yield 'negative' => ['-5'];
        yield 'empty' => [''];
    }

    public function testAuthStatusBecomesAuthException(): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => 403]));

        $this->expectException(ConnectorAuthException::class);
        $this->fetch($client);
    }

    public function testServerErrorBecomesTransientException(): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => 503]));

        $this->expectException(ConnectorTransientException::class);
        $this->fetch($client);
    }

    private function fetch(OzonOrdersClient $client): void
    {
        $client->fetchPostings(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            IngestOrderScheme::FBO,
            new \DateTimeImmutable('2026-09-01T00:00:00+00:00'),
            new \DateTimeImmutable('2026-09-01T01:00:00+00:00'),
            100,
            0,
        );
    }

    private function client(MockResponse $response): OzonOrdersClient
    {
        return new OzonOrdersClient(
            new MockHttpClient($response),
            $this->credentialProvider(),
            new NullLogger(),
        );
    }

    private function credentialProvider(): OzonCredentialProviderInterface
    {
        return new class implements OzonCredentialProviderInterface {
            public function read(string $companyId, string $connectionRef): array
            {
                return ['client_id' => 'client-id', 'api_key' => 'api-key'];
            }
        };
    }
}
