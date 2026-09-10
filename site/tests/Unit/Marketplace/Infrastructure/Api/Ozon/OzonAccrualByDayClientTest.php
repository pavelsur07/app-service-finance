<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Infrastructure\Api\Ozon;

use App\Marketplace\Exception\MarketplaceBadRequestException;
use App\Marketplace\Exception\MarketplaceRateLimitException;
use App\Marketplace\Infrastructure\Api\Ozon\OzonAccrualByDayClient;
use App\Marketplace\Infrastructure\Query\MarketplaceCredentialsQuery;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Contract\SecretRotationServiceInterface;
use App\Shared\Service\AppLogger;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Форма запроса и ответа взята из реальной выгрузки за июнь 2026:
 * тело `{"date": "..."}`, ответ `{"accruals": [...], "last_id": "..."}`,
 * пагинация продолжается, пока `last_id` непустой.
 */
final class OzonAccrualByDayClientTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';

    public function testFetchesSinglePageAndSendsDateInBody(): void
    {
        $capturedUrl = '';
        $capturedBody = '';
        $capturedHeaders = [];

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody, &$capturedHeaders): MockResponse {
            $capturedUrl = $url;
            $capturedBody = (string) ($options['body'] ?? '');
            $capturedHeaders = $options['headers'] ?? [];

            return new MockResponse('{"accruals":[{"accrual_id":1}],"last_id":""}', ['http_code' => 200]);
        });

        $rows = $this->client($http)->fetchDay(self::COMPANY_ID, new \DateTimeImmutable('2026-09-08'));

        self::assertSame([['accrual_id' => 1]], $rows);
        self::assertSame('https://api-seller.ozon.ru/v1/finance/accrual/by-day', $capturedUrl);
        self::assertStringContainsString('"date":"2026-09-08"', $capturedBody);
        self::assertStringNotContainsString('last_id', $capturedBody);
        self::assertContains('Client-Id: client-1', $capturedHeaders);
        self::assertContains('Api-Key: key-1', $capturedHeaders);
    }

    public function testFollowsLastIdPaginationUntilItIsEmpty(): void
    {
        $bodies = [];

        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = (string) ($options['body'] ?? '');

            return match (count($bodies)) {
                1 => new MockResponse('{"accruals":[{"accrual_id":1}],"last_id":"cursor-A"}', ['http_code' => 200]),
                2 => new MockResponse('{"accruals":[{"accrual_id":2}],"last_id":"cursor-B"}', ['http_code' => 200]),
                default => new MockResponse('{"accruals":[{"accrual_id":3}],"last_id":""}', ['http_code' => 200]),
            };
        });

        $rows = $this->client($http)->fetchDay(self::COMPANY_ID, new \DateTimeImmutable('2026-09-08'));

        self::assertSame([['accrual_id' => 1], ['accrual_id' => 2], ['accrual_id' => 3]], $rows);
        self::assertCount(3, $bodies);
        self::assertStringNotContainsString('last_id', $bodies[0]);
        self::assertStringContainsString('"last_id":"cursor-A"', $bodies[1]);
        self::assertStringContainsString('"last_id":"cursor-B"', $bodies[2]);
    }

    public function testEmptyDayReturnsEmptyListWithoutError(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"accruals":[],"last_id":""}', ['http_code' => 200]));

        self::assertSame([], $this->client($http)->fetchDay(self::COMPANY_ID, new \DateTimeImmutable('2026-09-08')));
    }

    public function testRateLimitIsSignalledSeparatelySoCallerCanRetry(): void
    {
        // Лимит у Ozon посекундный, и ключ делится с почасовыми загрузками
        // заказов и минутными поллерами рекламы. Это не инцидент, а повод
        // повторить, поэтому отдельный тип исключения.
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"code":8, "message":"You have reached request rate limit per second"}',
            ['http_code' => 429],
        ));

        $this->expectException(MarketplaceRateLimitException::class);

        $this->client($http)->fetchDay(self::COMPANY_ID, new \DateTimeImmutable('2026-09-08'));
    }

    public function testNonRetryableErrorCarriesOzonResponseBody(): void
    {
        // Диагноз должен быть в исключении: именно отсутствие тела ответа не
        // дало опознать снятие v3 в ночь на 09.09.2026.
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"code":9, "message":"obsolete method cannot be used"}',
            ['http_code' => 400],
        ));

        try {
            $this->client($http)->fetchDay(self::COMPANY_ID, new \DateTimeImmutable('2026-09-08'));
            self::fail('Снятый метод обязан приводить к ошибке.');
        } catch (MarketplaceBadRequestException $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertStringContainsString('obsolete method cannot be used', $e->getResponseExcerpt());
        }
    }

    public function testMissingCredentialsFailFast(): void
    {
        $client = new OzonAccrualByDayClient(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('{}')),
            $this->credentialsQuery(null),
            new AppLogger(new NullLogger()),
        );

        $this->expectException(\RuntimeException::class);

        $client->fetchDay(self::COMPANY_ID, new \DateTimeImmutable('2026-09-08'));
    }

    private function client(MockHttpClient $http): OzonAccrualByDayClient
    {
        return new OzonAccrualByDayClient(
            $http,
            $this->credentialsQuery(['api_key' => 'key-1', 'api_key_encrypted' => null, 'api_key_key_version' => null, 'client_id' => 'client-1']),
            new AppLogger(new NullLogger()),
        );
    }

    /**
     * MarketplaceCredentialsQuery объявлен final и подменяться не может,
     * поэтому собирается настоящий поверх мока DBAL — как в соседних тестах
     * клиентов Ozon.
     *
     * @param array<string, mixed>|null $row
     */
    private function credentialsQuery(?array $row): MarketplaceCredentialsQuery
    {
        $dbal = $this->createMock(Connection::class);
        $dbal->method('fetchAssociative')->willReturn($row ?? false);

        return new MarketplaceCredentialsQuery(
            $dbal,
            new ConnectionApiKeyCodec(
                $this->createMock(FieldEncryptionServiceInterface::class),
                $this->createMock(SecretRotationServiceInterface::class),
            ),
        );
    }
}
