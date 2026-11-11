<?php

declare(strict_types=1);

namespace App\Shared\Api\Response;

use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'SpaCsrfResponse', required: ['tokens'], properties: [new OA\Property(property: 'tokens', type: 'object', required: ['login', 'logout', 'company_select'], properties: [new OA\Property(property: 'login', type: 'string'), new OA\Property(property: 'logout', type: 'string'), new OA\Property(property: 'company_select', type: 'string')])])]
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
