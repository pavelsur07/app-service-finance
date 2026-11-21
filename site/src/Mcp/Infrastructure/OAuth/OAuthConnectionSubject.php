<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\UserEntityInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OAuthConnectionSubject implements UserEntityInterface
{
    /** @param non-empty-string $id */
    public function __construct(private string $id)
    {
    }

    public function getIdentifier(): string
    {
        return $this->id;
    }
}
