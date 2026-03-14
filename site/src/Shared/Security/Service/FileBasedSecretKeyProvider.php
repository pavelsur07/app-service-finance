<?php

declare(strict_types=1);

namespace App\Shared\Security\Service;

use App\Shared\Security\Contract\SecretKeyProviderInterface;
use App\Shared\Security\Exception\MissingEncryptionKeyException;

final readonly class FileBasedSecretKeyProvider implements SecretKeyProviderInterface
{
    public function __construct(
        private string $keyFile,
        private string $currentKeyVersion,
        private ?string $fallbackKeyFromEnv = null,
    ) {
    }

    public function getActiveKeyVersion(): string
    {
        return $this->currentKeyVersion;
    }

    public function getKeyByVersion(string $keyVersion): string
    {
        $normalizedVersion = trim($keyVersion);
        if ('' === $normalizedVersion) {
            throw new MissingEncryptionKeyException('Encryption key version is required.');
        }

        $keyMaterial = $this->resolveKeyMaterial($normalizedVersion);

        return $this->decodeAndValidateKey($keyMaterial);
    }

    private function resolveKeyMaterial(string $keyVersion): string
    {
        $keys = $this->readKeysFromFile();
        if (array_key_exists($keyVersion, $keys)) {
            return $keys[$keyVersion];
        }

        if ($keyVersion === $this->currentKeyVersion && null !== $this->fallbackKeyFromEnv && '' !== trim($this->fallbackKeyFromEnv)) {
            return $this->fallbackKeyFromEnv;
        }

        throw new MissingEncryptionKeyException('Encryption key is not configured for requested version.');
    }

    /**
     * @return array<string, string>
     */
    private function readKeysFromFile(): array
    {
        $path = trim($this->keyFile);
        if ('' === $path || !is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MissingEncryptionKeyException('Encryption key file has invalid format.');
        }

        if (!is_array($decoded)) {
            throw new MissingEncryptionKeyException('Encryption key file has invalid format.');
        }

        $keys = [];
        foreach ($decoded as $version => $material) {
            if (!is_string($version) || !is_string($material)) {
                continue;
            }

            $normalizedVersion = trim($version);
            $normalizedMaterial = trim($material);
            if ('' === $normalizedVersion || '' === $normalizedMaterial) {
                continue;
            }

            $keys[$normalizedVersion] = $normalizedMaterial;
        }

        return $keys;
    }

    private function decodeAndValidateKey(string $keyMaterial): string
    {
        $normalized = trim($keyMaterial);
        if ('' === $normalized) {
            throw new MissingEncryptionKeyException('Encryption key material is empty.');
        }

        $decoded = base64_decode($normalized, true);
        if (false === $decoded || $this->secretboxKeyBytes() !== strlen($decoded)) {
            throw new MissingEncryptionKeyException('Encryption key material must be a base64-encoded 256-bit key.');
        }

        return $decoded;
    }

    private function secretboxKeyBytes(): int
    {
        return \defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') ? (int) \constant('SODIUM_CRYPTO_SECRETBOX_KEYBYTES') : 32;
    }
}
