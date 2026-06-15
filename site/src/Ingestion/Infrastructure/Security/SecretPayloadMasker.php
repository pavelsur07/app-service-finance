<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Security;

final readonly class SecretPayloadMasker
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function mask(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            $masked[$key] = $this->maskValue($value);
        }

        return $masked;
    }

    private function maskValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $masked = [];
            foreach ($value as $key => $nestedValue) {
                $masked[$key] = $this->maskValue($nestedValue);
            }

            return $masked;
        }

        if (null === $value) {
            return null;
        }

        return '***';
    }
}
