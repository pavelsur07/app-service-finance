<?php

declare(strict_types=1);

namespace App\BankSync\Domain\OAuth;

use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Exception\BankIdTokenInvalidException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Вход клиента в банк по OAuth 2.0 Authorization Code + PKCE (+ OpenID Connect, если банк
 * его поддерживает): адреса, scope, пара приложения и разбор ответа — у реализации банка.
 * Общий HTTP token endpoint — `OAuthTokenEndpointClient`.
 */
#[AutoconfigureTag(self::TAG)]
interface BankAuthorizationProviderInterface
{
    public const TAG = 'bank_sync.authorization_provider';

    public function code(): BankCode;

    /**
     * Среды банка (базовые URL API), для которых вход настроен. Пусто — кнопки входа нет.
     *
     * @return list<string>
     */
    public function environments(): array;

    /**
     * @throws BankAuthorizationNotConfiguredException среда не настроена
     */
    public function authorizationUrl(AuthorizationRequest $request): string;

    /**
     * @throws BankAuthorizationRejectedException код или verifier отвергнут — вход заново
     * @throws BankIdTokenInvalidException `id_token` не прошёл проверку — токены не сохранять
     * @throws BankAuthorizationNotConfiguredException среда не настроена
     * @throws BankChannelNotConfiguredException канал до банка (mTLS) не настроен
     * @throws BankApiUnavailableException временный сбой
     * @throws BankApiRequestFailedException ошибка настройки или нечитаемый ответ
     */
    public function exchangeCode(
        string $baseUrl,
        #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $codeVerifier,
        string $redirectUri,
        #[\SensitiveParameter] string $nonce,
    ): AuthorizationGrant;

    /**
     * @throws BankAuthorizationRejectedException refresh отозван, истёк или израсходован — вход заново
     * @throws BankAuthorizationNotConfiguredException среда не настроена
     * @throws BankChannelNotConfiguredException канал до банка (mTLS) не настроен
     * @throws BankApiUnavailableException временный сбой
     * @throws BankApiRequestFailedException ошибка настройки или нечитаемый ответ
     */
    public function refresh(string $baseUrl, #[\SensitiveParameter] string $refreshToken): OAuthTokens;

    /**
     * Отзыв refresh-токена в банке (гасит и access) перед удалением подключения.
     *
     * @throws BankAuthorizationNotConfiguredException среда не настроена
     * @throws BankChannelNotConfiguredException канал до банка (mTLS) не настроен
     * @throws BankApiUnavailableException временный сбой
     * @throws BankApiRequestFailedException банк отказал или ответ нечитаем
     */
    public function revoke(string $baseUrl, #[\SensitiveParameter] string $refreshToken): void;
}
