<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\Security;

use App\Ingestion\Infrastructure\Security\PlaintextSecretCodec;
use PHPUnit\Framework\TestCase;

final class PlaintextSecretCodecTest extends TestCase
{
    public function testEncodeDecodeVersionZeroReturnsOriginalPayload(): void
    {
        $codec = new PlaintextSecretCodec();
        $payload = [
            'api_key' => 'secret-api-key',
            'client_id' => 'client-1',
            'nested' => ['token' => 'secret-token'],
        ];

        $stored = $codec->encode($payload);

        self::assertJson($stored);
        self::assertSame($payload, $codec->decode($stored, PlaintextSecretCodec::KEY_VERSION));
    }

    public function testDecodeBranchesByKeyVersion(): void
    {
        $codec = new PlaintextSecretCodec();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported secret key version "1".');

        $codec->decode('{"api_key":"secret-api-key"}', 1);
    }
}
