<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Api\Ozon;

use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Exception\ConnectorAuthException;
use App\Ingestion\Exception\ConnectorRateLimitedException;
use App\Ingestion\Exception\ConnectorTransientException;
use App\Ingestion\Exception\MalformedConnectorResponseException;
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

    /**
     * Отсутствующий has_next раньше молча означал «страниц больше нет» и
     * обрывал обход на первой странице. Пропуск не виден ничем.
     */
    public function testFbsMissingHasNextIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":[]}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * Строка "false" приводилась к true и давала лишний круг пагинации.
     */
    public function testFbsStringHasNextIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":[],"has_next":"false"}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * Не-объект в списке раньше выбрасывался молча. У FBO это опаснее всего:
     * счётчик строк — единственный признак полной страницы, и выброшенный
     * элемент оборвал бы обход раньше времени.
     */
    public function testNonObjectPostingIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":[{"posting_number":"p-1"},"broken"]}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    public function testInvalidJsonIsMalformed(): void
    {
        $client = $this->client(new MockResponse('not json', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    /**
     * Ассоциативный контейнер проходит is_array(), но его count() участвует в
     * решении о пагинации: обход закончился бы на произвольном числе
     * элементов.
     */
    public function testAssociativeResultIsMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"first":{"posting_number":"p-1"}}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client);
    }

    public function testAssociativeFbsPostingsAreMalformed(): void
    {
        $client = $this->client(new MockResponse('{"result":{"postings":{"a":{"posting_number":"p-1"}},"has_next":false}}', ['http_code' => 200]));

        $this->expectException(MalformedConnectorResponseException::class);
        $this->fetch($client, IngestOrderScheme::FBS);
    }

    /**
     * 408 и 425 — временные по смыслу и подлежат повтору ровно как 5xx. Без
     * отдельной ветки таймаут шлюза становился неповторяемым malformed
     * response и убивал прогон.
     */
    #[DataProvider('transientStatusProvider')]
    public function testTimeoutStatusesAreTransient(int $statusCode): void
    {
        $client = $this->client(new MockResponse('{}', ['http_code' => $statusCode]));

        $this->expectException(ConnectorTransientException::class);
        $this->fetch($client);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function transientStatusProvider(): iterable
    {
        yield 'request timeout' => [408];
        yield 'too early' => [425];
        yield 'bad gateway' => [502];
        yield 'gateway timeout' => [504];
    }

    private function fetch(OzonOrdersClient $client, IngestOrderScheme $scheme = IngestOrderScheme::FBO): void
    {
        $client->fetchPostings(
            '0192f0c2-0000-7000-8000-000000000001',
            '0192f0c2-0000-7000-8000-000000000002',
            $scheme,
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
