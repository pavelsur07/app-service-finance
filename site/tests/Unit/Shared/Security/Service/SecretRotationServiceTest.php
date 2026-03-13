<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security\Service;

use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Service\SecretRotationService;
use App\Shared\Security\ValueObject\EncryptedPayload;
use PHPUnit\Framework\TestCase;

final class SecretRotationServiceTest extends TestCase
{
    public function testRotateChangesKeyVersion(): void
    {
        $payload = new EncryptedPayload('legacy', 'v1', new \DateTimeImmutable('-1 day'));

        $encryptionService = new class implements FieldEncryptionServiceInterface {
            public function encrypt(string $plaintext): EncryptedPayload
            {
                return new EncryptedPayload('rotated', 'v2', new \DateTimeImmutable());
            }

            public function decrypt(EncryptedPayload $payload): string
            {
                return 'plain';
            }
        };

        $provider = new class implements SecretKeyProviderInterface {
            public function getActiveKeyVersion(): string
            {
                return 'v2';
            }

            public function getKeyByVersion(string $keyVersion): string
            {
                return 'unused';
            }
        };

        $service = new SecretRotationService($provider, $encryptionService);

        self::assertTrue($service->needsRotation($payload));
        self::assertTrue($service->requiresReencryption($payload));

        $rotated = $service->rotate($payload);

        self::assertSame('v2', $rotated->keyVersion());
    }

    public function testRotateReturnsSamePayloadWhenNoRotationNeeded(): void
    {
        $payload = new EncryptedPayload('same', 'v2', new \DateTimeImmutable());

        $encryptionService = new class implements FieldEncryptionServiceInterface {
            public function encrypt(string $plaintext): EncryptedPayload
            {
                return new EncryptedPayload('unexpected', 'v2', new \DateTimeImmutable());
            }

            public function decrypt(EncryptedPayload $payload): string
            {
                return 'unexpected';
            }
        };

        $provider = new class implements SecretKeyProviderInterface {
            public function getActiveKeyVersion(): string
            {
                return 'v2';
            }

            public function getKeyByVersion(string $keyVersion): string
            {
                return 'unused';
            }
        };

        $service = new SecretRotationService($provider, $encryptionService);

        self::assertFalse($service->needsRotation($payload));
        self::assertSame($payload, $service->rotate($payload));
    }
}
