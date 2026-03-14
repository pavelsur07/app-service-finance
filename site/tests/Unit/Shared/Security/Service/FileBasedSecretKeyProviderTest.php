<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security\Service;

use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\Service\FileBasedSecretKeyProvider;
use PHPUnit\Framework\TestCase;

final class FileBasedSecretKeyProviderTest extends TestCase
{
    public function testReadsKeyFromFile(): void
    {
        $rawKey = random_bytes(32);
        $provider = new FileBasedSecretKeyProvider(
            keyFile: $this->createTempFile((string) json_encode(['v1' => base64_encode($rawKey)], JSON_THROW_ON_ERROR)),
            currentKeyVersion: 'v1',
            fallbackKeyFromEnv: null,
        );

        self::assertSame('v1', $provider->getActiveKeyVersion());
        self::assertSame($rawKey, $provider->getKeyByVersion('v1'));
    }

    public function testThrowsForEmptyKeyMaterialWithoutSecretLeak(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: $this->createTempFile((string) json_encode(['v1' => '   '], JSON_THROW_ON_ERROR)),
            currentKeyVersion: 'v1',
            fallbackKeyFromEnv: null,
        );

        try {
            $provider->getKeyByVersion('v1');
            self::fail('Expected missing key exception');
        } catch (MissingEncryptionKeyException $exception) {
            self::assertStringContainsString('not configured', $exception->getMessage());
            self::assertStringNotContainsString('v1', $exception->getMessage());
        }
    }

    public function testUsesFallbackForActiveVersion(): void
    {
        $rawKey = random_bytes(32);

        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/non/existent/path.json',
            currentKeyVersion: 'v2',
            fallbackKeyFromEnv: base64_encode($rawKey),
        );

        self::assertSame($rawKey, $provider->getKeyByVersion('v2'));
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
