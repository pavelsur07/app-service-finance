<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Security;

use App\Ingestion\Domain\Contract\SecretCodec;

final readonly class PlaintextSecretCodec implements SecretCodec
{
    public const KEY_VERSION = 0;

    public function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    public function decode(string $stored, int $keyVersion): array
    {
        return match ($keyVersion) {
            self::KEY_VERSION => $this->decodePlaintext($stored),
            default => throw new \InvalidArgumentException(sprintf('Unsupported secret key version "%d".', $keyVersion)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePlaintext(string $stored): array
    {
        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Stored plaintext secret payload is not valid JSON.', 0, $exception);
        }

        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Stored plaintext secret payload must decode to an array.');
        }

        return $decoded;
    }
}
