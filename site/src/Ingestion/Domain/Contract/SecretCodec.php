<?php

declare(strict_types=1);

namespace App\Ingestion\Domain\Contract;

interface SecretCodec
{
    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string;

    /**
     * @return array<string, mixed>
     */
    public function decode(string $stored, int $keyVersion): array;
}
