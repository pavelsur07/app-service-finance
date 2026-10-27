<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use App\BankSync\Domain\OAuth\OAuthTokens;

/**
 * Ответ token endpoint: токены и, если банк поддерживает OpenID Connect, непроверенный
 * `id_token` — проверяет провайдер банка.
 */
final readonly class OAuthTokenResponse
{
    public function __construct(
        public OAuthTokens $tokens,
        #[\SensitiveParameter]
        public ?string $idToken,
    ) {
    }

    /**
     * @return array{tokens: array{accessExpiresAt: string, hasRefreshToken: bool}, hasIdToken: bool}
     */
    public function __debugInfo(): array
    {
        return ['tokens' => $this->tokens->__debugInfo(), 'hasIdToken' => null !== $this->idToken];
    }
}
