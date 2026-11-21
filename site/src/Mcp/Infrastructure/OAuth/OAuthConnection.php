<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OAuthConnection
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $id,
        public string $companyId,
        public string $userId,
        public string $clientId,
        public string $resource,
        public array $scopes,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
