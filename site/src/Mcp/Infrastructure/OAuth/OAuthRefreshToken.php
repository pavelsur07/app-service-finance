<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\OAuth;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class OAuthRefreshToken implements RefreshTokenEntityInterface
{
    use EntityTrait;
    use RefreshTokenTrait;
}
