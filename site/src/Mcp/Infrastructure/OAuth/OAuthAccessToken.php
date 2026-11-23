<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class OAuthAccessToken implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * @param non-empty-string $issuer
     * @param non-empty-string $resource
     */
    public function __construct(private readonly string $issuer, private readonly string $resource)
    {
    }

    public function toString(): string
    {
        $this->initJwtConfiguration();
        $now = new \DateTimeImmutable();

        return $this->jwtConfiguration->builder()
            ->withHeader('typ', 'at+jwt')
            ->issuedBy($this->issuer)
            ->permittedFor($this->resource)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo($this->getUserIdentifier() ?? throw new \LogicException('A connection is required.'))
            ->withClaim('client_id', $this->getClient()->getIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->withClaim('scope', implode(' ', array_map(static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $this->getScopes())))
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey())
            ->toString();
    }
}
