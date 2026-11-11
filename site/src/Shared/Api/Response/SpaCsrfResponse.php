<?php

declare(strict_types=1);

namespace App\Shared\Api\Response;

final readonly class SpaCsrfResponse
{
    /** @param array<string, string> $tokens */
    public function __construct(private array $tokens)
    {
    }

    /** @return array{tokens: array<string, string>} */
    public function toArray(): array
    {
        return ['tokens' => $this->tokens];
    }
}
