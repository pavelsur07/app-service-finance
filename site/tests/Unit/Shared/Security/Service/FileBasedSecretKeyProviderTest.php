<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Security\Service;

use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\Service\FileBasedSecretKeyProvider;
use PHPUnit\Framework\TestCase;

final class FileBasedSecretKeyProviderTest extends TestCase
{
    private const KEY_V1 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; // 32 нулевых байта
    private const KEY_V2 = 'AQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQE='; // 32 байта 0x01

    private string $tmpKeyFile;

    protected function setUp(): void
    {
        $this->tmpKeyFile = tempnam(sys_get_temp_dir(), 'keys_') ?: '';
        self::assertFileExists($this->tmpKeyFile);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpKeyFile && is_file($this->tmpKeyFile)) {
            unlink($this->tmpKeyFile);
        }
    }

    public function testEnvJsonProvidesMultipleKeyVersions(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v2',
            keysJsonFromEnv: $this->keysJson(),
        );

        self::assertSame(str_repeat("\x00", 32), $provider->getKeyByVersion('v1'));
        self::assertSame(str_repeat("\x01", 32), $provider->getKeyByVersion('v2'));
    }

    public function testFileWinsOverEnvJson(): void
    {
        file_put_contents($this->tmpKeyFile, json_encode(['v1' => self::KEY_V2]));

        $provider = new FileBasedSecretKeyProvider(
            keyFile: $this->tmpKeyFile,
            currentKeyVersion: 'v1',
            keysJsonFromEnv: $this->keysJson(),
        );

        // Файл возвращает KEY_V2 для v1, а не KEY_V1 из env-карты
        self::assertSame(str_repeat("\x01", 32), $provider->getKeyByVersion('v1'));
    }

    public function testFallbackKeyStillWorksForCurrentVersion(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v1',
            fallbackKeyFromEnv: self::KEY_V1,
            keysJsonFromEnv: $this->keysJson(),
        );

        // v1 есть в env-карте (приоритет), но и fallback бы сработал
        self::assertSame(str_repeat("\x00", 32), $provider->getKeyByVersion('v1'));
    }

    public function testFallbackKeyIsNotUsedForNonCurrentVersion(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v2',
            fallbackKeyFromEnv: self::KEY_V1,
        );

        $this->expectException(MissingEncryptionKeyException::class);
        $provider->getKeyByVersion('v1');
    }

    public function testInvalidEnvJsonFallsThroughToOtherSources(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v1',
            fallbackKeyFromEnv: self::KEY_V1,
            keysJsonFromEnv: '{broken json',
        );

        self::assertSame(str_repeat("\x00", 32), $provider->getKeyByVersion('v1'));
    }

    public function testEmptyAndMalformedEntriesInEnvJsonAreSkipped(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v2',
            keysJsonFromEnv: json_encode(['' => self::KEY_V1, 'v2' => self::KEY_V2, 'v3' => '   ']),
        );

        self::assertSame(str_repeat("\x01", 32), $provider->getKeyByVersion('v2'));

        $this->expectException(MissingEncryptionKeyException::class);
        $provider->getKeyByVersion('v3');
    }

    public function testShortKeyMaterialIsRejected(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v1',
            keysJsonFromEnv: json_encode(['v1' => base64_encode('too-short')]),
        );

        $this->expectException(MissingEncryptionKeyException::class);
        $this->expectExceptionMessage('base64-encoded 256-bit key');
        $provider->getKeyByVersion('v1');
    }

    public function testMissingVersionThrows(): void
    {
        $provider = new FileBasedSecretKeyProvider(
            keyFile: '/nonexistent/keys.json',
            currentKeyVersion: 'v2',
            keysJsonFromEnv: $this->keysJson(),
        );

        $this->expectException(MissingEncryptionKeyException::class);
        $provider->getKeyByVersion('v99');
    }

    public function testEmptyVersionThrows(): void
    {
        $provider = new FileBasedSecretKeyProvider('/nonexistent/keys.json', 'v1');

        $this->expectException(MissingEncryptionKeyException::class);
        $provider->getKeyByVersion('  ');
    }

    public function testReadsKeyFromFile(): void
    {
        $rawKey = random_bytes(32);
        file_put_contents($this->tmpKeyFile, (string) json_encode(['v1' => base64_encode($rawKey)], JSON_THROW_ON_ERROR));

        $provider = new FileBasedSecretKeyProvider(
            keyFile: $this->tmpKeyFile,
            currentKeyVersion: 'v1',
            fallbackKeyFromEnv: null,
        );

        self::assertSame('v1', $provider->getActiveKeyVersion());
        self::assertSame($rawKey, $provider->getKeyByVersion('v1'));
    }

    public function testThrowsForEmptyKeyMaterialWithoutSecretLeak(): void
    {
        file_put_contents($this->tmpKeyFile, (string) json_encode(['v1' => '   '], JSON_THROW_ON_ERROR));

        $provider = new FileBasedSecretKeyProvider(
            keyFile: $this->tmpKeyFile,
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

    private function keysJson(): string
    {
        return (string) json_encode(['v1' => self::KEY_V1, 'v2' => self::KEY_V2]);
    }
}
