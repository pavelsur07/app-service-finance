<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

/**
 * Параметры перехода клиента на страницу входа банка. `baseUrl` выбирает среду банка
 * (песочница или прод) — по нему провайдер берёт адреса авторизации и пару приложения.
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $baseUrl,
        public string $state,
        public string $codeChallenge,
        public string $redirectUri,
        /** OpenID Connect: банк вернёт его в `id_token`, сверка при обмене кода. */
        #[\SensitiveParameter]
        public string $nonce,
    ) {
    }
}
