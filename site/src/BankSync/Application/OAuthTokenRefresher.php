<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Domain\OAuth\OAuthTokens;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationRejectedException;
use App\BankSync\Exception\UnknownBankCodeException;
use App\BankSync\Infrastructure\OAuth\BankAuthorizationProviderRegistry;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\Shared\Security\Exception\DecryptionException;
use App\Shared\Security\Exception\InvalidEncryptedPayloadException;
use App\Shared\Security\Exception\MissingEncryptionKeyException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Решает, пора ли обновлять OAuth-токены подключения, и обновляет их в сущности.
 * Не коммитит и не блокирует: вызывающий (Handler) держит блокировку токенов
 * подключения, перечитывает его до вызова и коммитит сразу после — иначе новый refresh,
 * уже выданный банком, потеряется.
 */
final readonly class OAuthTokenRefresher
{
    /** Access обновляется, если истекает раньше чем через столько секунд. */
    public const REFRESH_AHEAD_SECONDS = 300;

    public function __construct(
        private BankCredentialsCodec $credentialsCodec,
        private BankAuthorizationProviderRegistry $providers,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Дешёвая проверка до блокировки: статический токен и свежий access — false.
     *
     * @throws DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException
     */
    public function needsRefresh(BankConnection $connection): bool
    {
        $tokens = $this->credentialsCodec->oauthTokens($connection);

        return null !== $tokens && $tokens->accessExpiresWithin($this->clock->now(), self::REFRESH_AHEAD_SECONDS);
    }

    /**
     * @param string|null $rejectedAccessToken access, на который банк ответил 401: если в
     *                                         подключении всё ещё он — обновить, даже не истёкший;
     *                                         если уже другой — его обновил другой процесс
     *
     * @return bool true — токены заменены в сущности, нужен commit
     *
     * @throws BankAuthorizationRejectedException refresh отвергнут или его нет — нужен вход
     * @throws BankApiUnavailableException
     * @throws BankApiRequestFailedException
     * @throws UnknownBankCodeException у банка нет провайдера авторизации
     * @throws DecryptionException|InvalidEncryptedPayloadException|MissingEncryptionKeyException
     */
    public function refreshIfNeeded(BankConnection $connection, #[\SensitiveParameter] ?string $rejectedAccessToken = null): bool
    {
        $tokens = $this->credentialsCodec->oauthTokens($connection);
        if (null === $tokens) {
            return false;
        }

        $rejected = null !== $rejectedAccessToken && hash_equals($tokens->accessToken, $rejectedAccessToken);
        if (!$rejected && !$tokens->accessExpiresWithin($this->clock->now(), self::REFRESH_AHEAD_SECONDS)) {
            return false;
        }
        if (null === $tokens->refreshToken) {
            throw new BankAuthorizationRejectedException($connection->getBankCode()->value, null, 'no_refresh_token');
        }

        $fresh = $this->providers->get($connection->getBankCode())
            ->refresh($connection->getBaseUrl(), $tokens->refreshToken)
            ->withFallbackRefreshToken($tokens->refreshToken);
        $fresh = new OAuthTokens($fresh->accessToken, $fresh->refreshToken, $fresh->accessExpiresAt, $fresh->consentExpiresAt ?? $tokens->consentExpiresAt);

        $credentials = $this->credentialsCodec->encryptOAuthTokens($fresh);
        $connection->replaceCredentials($credentials->storageJson, $credentials->keyVersion);

        return true;
    }
}
