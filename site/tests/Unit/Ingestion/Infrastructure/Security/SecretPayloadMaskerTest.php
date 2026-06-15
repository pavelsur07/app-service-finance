<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Security;

use App\Ingestion\Infrastructure\Security\SecretPayloadMasker;
use PHPUnit\Framework\TestCase;

final class SecretPayloadMaskerTest extends TestCase
{
    public function testMaskRemovesPayloadValuesFromOutput(): void
    {
        $payload = [
            'api_key' => 'secret-api-key',
            'client_id' => 'client-1',
            'nested' => [
                'token' => 'secret-token',
                'nullable' => null,
            ],
        ];

        $masked = (new SecretPayloadMasker())->mask($payload);

        self::assertSame([
            'api_key' => '***',
            'client_id' => '***',
            'nested' => [
                'token' => '***',
                'nullable' => null,
            ],
        ], $masked);

        $encodedMasked = json_encode($masked, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-api-key', $encodedMasked);
        self::assertStringNotContainsString('client-1', $encodedMasked);
        self::assertStringNotContainsString('secret-token', $encodedMasked);
    }
}
