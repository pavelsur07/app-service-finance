<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Вход клиента в банк по OAuth 2.0 Authorization Code + PKCE: адреса, scope, пара
 * приложения и разбор ответа — у реализации банка. Общий HTTP token endpoint —
 * `OAuthTokenEndpointClient`.
 */
#[AutoconfigureTag(self::TAG)]
interface BankAuthorizationProviderInterface
{
    public const TAG = 'bank_sync.authorization_provider';

    public function code(): BankCode;

    public function authorizationUrl(AuthorizationRequest $request): string;

    /**
     * @throws BankAuthorizationRejectedException код или verifier отвергнут — вход заново
     * @throws BankApiUnavailableException временный сбой
     * @throws BankApiRequestFailedException ошибка настройки или нечитаемый ответ
     */
    public function exchangeCode(string $baseUrl, #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $codeVerifier, string $redirectUri): OAuthTokens;

    /**
     * @throws BankAuthorizationRejectedException refresh отозван, истёк или израсходован — вход заново
     * @throws BankApiUnavailableException временный сбой
     * @throws BankApiRequestFailedException ошибка настройки или нечитаемый ответ
     */
    public function refresh(string $baseUrl, #[\SensitiveParameter] string $refreshToken): OAuthTokens;
}
