<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Security;

use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Service\SodiumFieldEncryptionService;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Tests\Builders\MoySklad\MoySkladConnectionBuilder;
use PHPUnit\Framework\TestCase;

final class ConnectionTokenCodecTest extends TestCase
{
    public function testReplacementStoresNoPlaintextAndReadsEncryptedToken(): void
    {
        $codec = new ConnectionTokenCodec($this->encryption());
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('old-token');
        $codec->replaceAccessToken($connection, 'new-secret-token');
        self::assertNull($connection->getAccessToken());
        self::assertStringNotContainsString('new-secret-token', $connection->getAccessTokenEncrypted());
        self::assertSame('new-secret-token', $codec->accessTokenFor($connection));
    }

    public function testLegacyFallbackAndIdempotentMigrationOfBothTokens(): void
    {
        $codec = new ConnectionTokenCodec($this->encryption());
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('legacy-access')->setRefreshToken('legacy-refresh');
        self::assertSame('legacy-access', $codec->accessTokenFor($connection));
        self::assertTrue($codec->encryptExisting($connection));
        self::assertNull($connection->getAccessToken());
        self::assertNull($connection->getRefreshToken());
        self::assertSame('legacy-refresh', $this->encryption()->decrypt(EncryptedPayload::fromStorageJson($connection->getRefreshTokenEncrypted())));
        self::assertSame('legacy-access', $codec->accessTokenFor($connection));
        $payload = $connection->getAccessTokenEncrypted();
        self::assertFalse($codec->encryptExisting($connection));
        self::assertSame($payload, $connection->getAccessTokenEncrypted());
    }

    public function testEncryptionFailurePreservesBothOriginalTokens(): void
    {
        $encryption = $this->createMock(FieldEncryptionServiceInterface::class);
        $encryption->method('encrypt')->willReturnCallback(function (string $token): EncryptedPayload {
            if ('refresh' === $token) {
                throw new \RuntimeException('Encryption failed.');
            }

            return $this->encryption()->encrypt($token);
        });
        $encryption->method('decrypt')->willReturnCallback(fn (EncryptedPayload $payload): string => $this->encryption()->decrypt($payload));
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('access')->setRefreshToken('refresh');
        try {
            (new ConnectionTokenCodec($encryption))->encryptExisting($connection);
            self::fail('Expected encryption failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Encryption failed.', $exception->getMessage());
        }
        self::assertSame('access', $connection->getAccessToken());
        self::assertSame('refresh', $connection->getRefreshToken());
        self::assertNull($connection->getAccessTokenEncrypted());
    }

    public function testConflictingExistingCiphertextPreservesPlaintext(): void
    {
        $encryption = $this->encryption();
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('original');
        $connection->setAccessTokenEncrypted($encryption->encrypt('different')->toStorageJson());
        try {
            (new ConnectionTokenCodec($encryption))->encryptExisting($connection);
            self::fail('Expected mismatch failure.');
        } catch (\RuntimeException) {
            self::assertSame('original', $connection->getAccessToken());
        }
    }

    public function testFailedReplacementPreservesCurrentToken(): void
    {
        $encryption = $this->createStub(FieldEncryptionServiceInterface::class);
        $encryption->method('encrypt')->willThrowException(new \RuntimeException('Encryption failed.'));
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('current');
        try {
            (new ConnectionTokenCodec($encryption))->replaceAccessToken($connection, 'replacement');
            self::fail('Expected encryption failure.');
        } catch (\RuntimeException) {
            self::assertSame('current', $connection->getAccessToken());
            self::assertNull($connection->getAccessTokenEncrypted());
        }
    }

    public function testMatchingExistingCiphertextIsReused(): void
    {
        $encryption = $this->encryption();
        $payload = $encryption->encrypt('original')->toStorageJson();
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('original')->setAccessTokenEncrypted($payload);
        self::assertTrue((new ConnectionTokenCodec($encryption))->encryptExisting($connection));
        self::assertNull($connection->getAccessToken());
        self::assertSame($payload, $connection->getAccessTokenEncrypted());
    }

    public function testBrokenEncryptedTokenDoesNotFallBackToLegacy(): void
    {
        $connection = MoySkladConnectionBuilder::aConnection()->build()->setAccessToken('original')->setAccessTokenEncrypted('broken');
        $this->expectException(\App\Shared\Security\Exception\InvalidEncryptedPayloadException::class);
        (new ConnectionTokenCodec($this->encryption()))->accessTokenFor($connection);
    }

    private function encryption(): SodiumFieldEncryptionService
    {
        $provider = $this->createStub(SecretKeyProviderInterface::class);
        $provider->method('getActiveKeyVersion')->willReturn('test');
        $provider->method('getKeyByVersion')->willReturn(str_repeat('x', 32));

        return new SodiumFieldEncryptionService($provider);
    }
}
