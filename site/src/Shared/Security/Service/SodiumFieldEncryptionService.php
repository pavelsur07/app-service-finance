<?php

declare(strict_types=1);

namespace App\Shared\Security\Service;

use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\EncryptionException;
use App\Shared\Security\ValueObject\EncryptedPayload;

final readonly class SodiumFieldEncryptionService implements FieldEncryptionServiceInterface
{
    public function __construct(
        private SecretKeyProviderInterface $secretKeyProvider,
    ) {
    }

    public function encrypt(string $plaintext): EncryptedPayload
    {
        $keyVersion = $this->secretKeyProvider->getActiveKeyVersion();
        $key = $this->secretKeyProvider->getKeyByVersion($keyVersion);

        try {
            $nonce = random_bytes($this->secretboxNonceBytes());
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } catch (\Throwable $exception) {
            throw new EncryptionException('Unable to encrypt value.', 0, $exception);
        }

        return new EncryptedPayload(
            ciphertext: base64_encode($nonce.$ciphertext),
            keyVersion: $keyVersion,
            encryptedAt: new \DateTimeImmutable(),
        );
    }

    public function decrypt(EncryptedPayload $payload): string
    {
        $decodedPayload = base64_decode($payload->ciphertext(), true);
        if (false === $decodedPayload) {
            throw new DecryptionException('Encrypted payload has invalid encoding.');
        }

        $nonceLength = $this->secretboxNonceBytes();
        if (strlen($decodedPayload) <= $nonceLength) {
            throw new DecryptionException('Encrypted payload is malformed.');
        }

        $nonce = substr($decodedPayload, 0, $nonceLength);
        $ciphertext = substr($decodedPayload, $nonceLength);

        $key = $this->secretKeyProvider->getKeyByVersion($payload->keyVersion());

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);
        } catch (\Throwable $exception) {
            throw new DecryptionException('Unable to decrypt value.', 0, $exception);
        }

        if (false === $plaintext) {
            throw new DecryptionException('Encrypted payload authentication failed.');
        }

        return $plaintext;
    }

    private function secretboxNonceBytes(): int
    {
        return \defined('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES') ? (int) \constant('SODIUM_CRYPTO_SECRETBOX_NONCEBYTES') : 24;
    }
}
