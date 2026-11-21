<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OAuthClient implements ClientEntityInterface
{
    /**
     * @param non-empty-string $id
     * @param list<string> $redirectUris
     */
    public function __construct(private string $id, private array $redirectUris)
    {
    }

    public function getIdentifier(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->id;
    }

    /** @return list<string> */
    public function getRedirectUri(): array
    {
        return $this->redirectUris;
    }

    public function isConfidential(): bool
    {
        return false;
    }
}
