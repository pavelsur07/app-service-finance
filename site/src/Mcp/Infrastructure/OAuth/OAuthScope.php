<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use App\Mcp\Security\McpScope;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OAuthScope implements ScopeEntityInterface
{
    public function __construct(private McpScope $scope)
    {
    }

    public function getIdentifier(): string
    {
        return $this->scope->value;
    }

    public function jsonSerialize(): string
    {
        return $this->getIdentifier();
    }
}
