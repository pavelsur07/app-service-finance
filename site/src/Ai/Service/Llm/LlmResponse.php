<?php

declare(strict_types=1);

namespace App\Ai\Service\Llm;

use JsonException;

final class LlmResponse
{
    private ?array $json;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $raw,
        private readonly array $payload = [],
    ) {
        $this->json = $this->tryDecodeJson($raw);
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<mixed>|null
     */
    public function getJson(): ?array
    {
        return $this->json;
    }

    private function tryDecodeJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        if ('' === $trimmed) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
