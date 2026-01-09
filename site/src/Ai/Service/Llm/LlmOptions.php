<?php

declare(strict_types=1);

namespace App\Ai\Service\Llm;

final class LlmOptions
{
    private function __construct(
        public readonly string $model,
        public readonly float $temperature,
        public readonly int $maxTokens,
    ) {
    }

    public static function forFinancialAssistant(): self
    {
        return new self('gpt-4o-mini', 0.2, 800);
    }

    public static function custom(string $model, float $temperature, int $maxTokens): self
    {
        return new self($model, $temperature, $maxTokens);
    }
}
