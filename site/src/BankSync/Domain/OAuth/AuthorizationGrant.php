<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

/**
 * Итог обмена кода: токены и идентификатор пользователя у банка (`sub` из проверенного
 * `id_token`, без ФИО). По `sub` подключение находится в ответах банка о согласиях.
 */
final readonly class AuthorizationGrant
{
    public function __construct(
        public OAuthTokens $tokens,
        public ?string $externalSubject,
    ) {
    }

    /**
     * @return array{tokens: array{accessExpiresAt: string, hasRefreshToken: bool}, hasExternalSubject: bool}
     */
    public function __debugInfo(): array
    {
        return ['tokens' => $this->tokens->__debugInfo(), 'hasExternalSubject' => null !== $this->externalSubject];
    }
}
