<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory\Infrastructure\Api\OzonFetcher;

use App\Inventory\Infrastructure\Api\OzonFetcher\OzonSupplySnapshotClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OzonSupplySnapshotClientTest extends TestCase
{
    /**
     * 13.11.2026: крупные кабинеты обрывались на idle 10 с — сервис собирает страницы снимка
     * из S3 до первого байта ответа. Ночной cron должен ждать минуты, а не секунды.
     */
    public function testWaitsMinutesForLargeSnapshots(): void
    {
        $options = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $o) use (&$options): MockResponse {
            $options = $o;

            return new MockResponse('', ['http_code' => 404]);
        });

        (new OzonSupplySnapshotClient($http, new NullLogger(), 'http://ozon-fetcher:8080', 'token'))
            ->fetchLatest('77777777-7777-7777-7777-000000000001');

        self::assertGreaterThanOrEqual(120.0, $options['timeout']);
        self::assertGreaterThanOrEqual(120.0, $options['max_duration']);
    }
}
