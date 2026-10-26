<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Security;

use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Infrastructure\Security\EncryptedBankCredentials;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\EncryptionException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use App\Shared\Security\Service\SodiumFieldEncryptionService;
use App\Shared\Security\ValueObject\EncryptedPayload;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use PHPUnit\Framework\TestCase;

final class BankCredentialsCodecTest extends TestCase
{
    private const TOKEN = 'secret-bank-token';

    public function testTokenRoundTripsAndCiphertextHasNoToken(): void
    {
        $codec = new BankCredentialsCodec($this->encryption());

        $credentials = $codec->encryptStaticToken(self::TOKEN);

        self::assertSame('v-test', $credentials->keyVersion);
        self::assertStringNotContainsString(self::TOKEN, $credentials->storageJson);
        $connection = BankConnectionBuilder::aConnection()->withCredentials($credentials)->build();
        self::assertSame(self::TOKEN, $codec->token($connection));
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(EncryptionException::class);

        (new BankCredentialsCodec($this->encryption()))->encryptStaticToken('  ');
    }

    public function testCiphertextThatDoesNotDecryptBackIsRejected(): void
    {
        $real = $this->encryption();
        $encryption = $this->createStub(FieldEncryptionServiceInterface::class);
        $encryption->method('encrypt')->willReturnCallback(static fn (string $plaintext): EncryptedPayload => $real->encrypt($plaintext));
        $encryption->method('decrypt')->willReturn('{"type":"static_token","token":"other"}');

        $this->expectException(EncryptionException::class);

        (new BankCredentialsCodec($encryption))->encryptStaticToken(self::TOKEN);
    }

    public function testUnknownKeyVersionFailsWithoutTokenInMessage(): void
    {
        $credentials = (new BankCredentialsCodec($this->encryption()))->encryptStaticToken(self::TOKEN);
        $provider = $this->createStub(SecretKeyProviderInterface::class);
        $provider->method('getActiveKeyVersion')->willReturn('v-new');
        $provider->method('getKeyByVersion')->willThrowException(new MissingEncryptionKeyException('Encryption key version "v-test" is not configured.'));
        $codec = new BankCredentialsCodec(new SodiumFieldEncryptionService($provider));

        try {
            $codec->token(BankConnectionBuilder::aConnection()->withCredentials($credentials)->build());
            self::fail('Decryption must fail without the key.');
        } catch (\RuntimeException $exception) {
            for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
                self::assertStringNotContainsString(self::TOKEN, $current->getMessage());
            }
        }
    }

    public function testUnexpectedPlaintextFormatIsRejected(): void
    {
        $encryption = $this->encryption();
        $payload = $encryption->encrypt('{"type":"oauth","access":"x"}');
        $connection = BankConnectionBuilder::aConnection()
            ->withCredentials(new EncryptedBankCredentials($payload->toStorageJson(), 'v-test'))
            ->build();

        $this->expectException(DecryptionException::class);

        (new BankCredentialsCodec($encryption))->token($connection);
    }

    private function encryption(): SodiumFieldEncryptionService
    {
        $provider = $this->createStub(SecretKeyProviderInterface::class);
        $provider->method('getActiveKeyVersion')->willReturn('v-test');
        $provider->method('getKeyByVersion')->willReturn(str_repeat('x', 32));

        return new SodiumFieldEncryptionService($provider);
    }
}
