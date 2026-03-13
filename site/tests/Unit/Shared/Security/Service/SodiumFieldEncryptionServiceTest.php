<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security\Service;

use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\Service\FileBasedSecretKeyProvider;
use App\Shared\Security\Service\SecretRotationService;
use App\Shared\Security\Service\SodiumFieldEncryptionService;
use App\Shared\Security\ValueObject\EncryptedPayload;
use PHPUnit\Framework\TestCase;

final class SodiumFieldEncryptionServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!\extension_loaded('sodium')) {
            self::markTestSkipped('The sodium extension is required for encryption tests.');
        }
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $provider = $this->providerWithKeys([
            'v1' => base64_encode(random_bytes(32)),
        ], 'v1');

        $service = new SodiumFieldEncryptionService($provider);

        $payload = $service->encrypt('super-secret-value');

        self::assertNotSame('super-secret-value', $payload->ciphertext());
        self::assertSame('v1', $payload->keyVersion());
        self::assertSame('super-secret-value', $service->decrypt($payload));
    }

    public function testThrowsWhenKeyIsEmpty(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: $this->createTempFile('{"v1":"   "}'),
            currentKeyVersion: 'v1',
            fallbackKeyFromEnv: null,
        );

        $service = new SodiumFieldEncryptionService($provider);

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionMessage('Encryption key is not configured for requested version.');

        $service->encrypt('data');
    }

    public function testThrowsOnBrokenPayloadWithoutLeakingSensitiveData(): void
    {
        $provider = $this->providerWithKeys([
            'v1' => base64_encode(random_bytes(32)),
        ], 'v1');
        $service = new SodiumFieldEncryptionService($provider);

        $payload = new EncryptedPayload('!!!not-base64!!!', 'v1', new \DateTimeImmutable());

        try {
            $service->decrypt($payload);
            self::fail('Expected decryption exception.');
        } catch (DecryptionException $exception) {
            self::assertStringContainsString('invalid encoding', $exception->getMessage());
            self::assertStringNotContainsString('!!!not-base64!!!', $exception->getMessage());
        }
    }

    public function testRotateChangesKeyVersionAndSupportsLegacyDecrypt(): void
    {
        $oldKey = base64_encode(random_bytes(32));
        $newKey = base64_encode(random_bytes(32));

        $oldProvider = $this->providerWithKeys(['v1' => $oldKey], 'v1');
        $oldEncryptionService = new SodiumFieldEncryptionService($oldProvider);
        $legacyPayload = $oldEncryptionService->encrypt('legacy-data');

        $rotationProvider = $this->providerWithKeys([
            'v1' => $oldKey,
            'v2' => $newKey,
        ], 'v2');
        $currentEncryptionService = new SodiumFieldEncryptionService($rotationProvider);
        $rotationService = new SecretRotationService($rotationProvider, $currentEncryptionService);

        self::assertTrue($rotationService->needsRotation($legacyPayload));

        $rotatedPayload = $rotationService->rotate($legacyPayload);

        self::assertSame('v2', $rotatedPayload->keyVersion());
        self::assertSame('legacy-data', $currentEncryptionService->decrypt($legacyPayload));
        self::assertSame('legacy-data', $currentEncryptionService->decrypt($rotatedPayload));
    }

    private function providerWithKeys(array $keys, string $activeVersion): FileBasedSecretKeyProvider
    {
        return new FileBasedSecretKeyProvider(
            keyFile: $this->createTempFile((string) json_encode($keys, JSON_THROW_ON_ERROR)),
            currentKeyVersion: $activeVersion,
            fallbackKeyFromEnv: null,
        );
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'enc_keys_');
        if (false === $path) {
            self::fail('Unable to create temp file.');
        }

        file_put_contents($path, $content);

        return $path;
    }
}
