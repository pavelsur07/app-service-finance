<?php

declare(strict_types=1);

namespace App\Mcp\Security;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class McpPrincipal
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $companyId,
        public string $userId,
        public string $connectionId,
        public array $scopes,
    ) {
    }
}
