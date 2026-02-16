<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Tests\Support\Kernel\WebTestCaseBase;

final class HealthControllerTest extends WebTestCaseBase
{
    public function testLiveEndpointReturnsOk(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health/live');

        self::assertResponseStatusCodeSame(200);
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testReadyEndpointReturnsOk(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/health/ready');

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status'] ?? null);
        self::assertTrue($payload['checks']['postgres'] ?? null);
        self::assertTrue($payload['checks']['redis_cache'] ?? null);
    }
}
