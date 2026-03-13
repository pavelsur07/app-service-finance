<?php

declare(strict_types=1);

namespace App\Shared\Security\ValueObject;

use App\Shared\Security\Exception\InvalidEncryptedPayloadException;

final readonly class EncryptedPayload
{
    public function __construct(
        private string $ciphertext,
        private string $keyVersion,
        private \DateTimeImmutable $encryptedAt,
    ) {
        if ('' === trim($this->ciphertext)) {
            throw new InvalidEncryptedPayloadException('Encrypted payload ciphertext must not be empty.');
        }

        if ('' === trim($this->keyVersion)) {
            throw new InvalidEncryptedPayloadException('Encrypted payload keyVersion must not be empty.');
        }
    }

    public function ciphertext(): string
    {
        return $this->ciphertext;
    }

    public function keyVersion(): string
    {
        return $this->keyVersion;
    }

    public function encryptedAt(): \DateTimeImmutable
    {
        return $this->encryptedAt;
    }

    /**
     * @return array{ciphertext: string, keyVersion: string, encryptedAt: string}
     */
    public function toStorageArray(): array
    {
        return [
            'ciphertext' => $this->ciphertext,
            'keyVersion' => $this->keyVersion,
            'encryptedAt' => $this->encryptedAt->format(DATE_ATOM),
        ];
    }

    public function toStorageJson(): string
    {
        return (string) json_encode($this->toStorageArray(), JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{ciphertext?: mixed, keyVersion?: mixed, encryptedAt?: mixed} $payload
     */
    public static function fromStorageArray(array $payload): self
    {
        if (!isset($payload['ciphertext']) || !is_string($payload['ciphertext'])) {
            throw new InvalidEncryptedPayloadException('Encrypted payload ciphertext is missing or invalid.');
        }

        if (!isset($payload['keyVersion']) || !is_string($payload['keyVersion'])) {
            throw new InvalidEncryptedPayloadException('Encrypted payload keyVersion is missing or invalid.');
        }

        if (!isset($payload['encryptedAt']) || !is_string($payload['encryptedAt'])) {
            throw new InvalidEncryptedPayloadException('Encrypted payload encryptedAt is missing or invalid.');
        }

        try {
            $encryptedAt = new \DateTimeImmutable($payload['encryptedAt']);
        } catch (\Exception $exception) {
            throw new InvalidEncryptedPayloadException('Encrypted payload encryptedAt must be a valid datetime string.', 0, $exception);
        }

        return new self(
            ciphertext: $payload['ciphertext'],
            keyVersion: $payload['keyVersion'],
            encryptedAt: $encryptedAt,
        );
    }

    public static function fromStorageJson(string $payload): self
    {
        if ('' === trim($payload)) {
            throw new InvalidEncryptedPayloadException('Encrypted payload JSON must not be empty.');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidEncryptedPayloadException('Encrypted payload JSON is invalid.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new InvalidEncryptedPayloadException('Encrypted payload JSON must decode to an object.');
        }

        return self::fromStorageArray($decoded);
    }
}
