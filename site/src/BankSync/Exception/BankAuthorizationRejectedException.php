<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Банк отверг код, refresh или токен доступа (400/401 token endpoint, 401 API данных):
 * повтор не поможет, нужен новый вход клиента. Подключение переходит в `needs_reauth`.
 *
 * `oauthError` — код ошибки OAuth (`invalid_grant`…), только если он выглядит как код:
 * описание ошибки банка в сообщение не попадает.
 */
final class BankAuthorizationRejectedException extends \RuntimeException
{
    private readonly ?string $oauthError;

    public function __construct(
        private readonly string $bankCode,
        private readonly ?int $statusCode,
        ?string $oauthError = null,
    ) {
        $this->oauthError = null !== $oauthError && 1 === preg_match('/^[a-z_]{1,64}$/', $oauthError) ? $oauthError : null;

        parent::__construct(sprintf(
            'Bank "%s" rejected the authorization%s%s.',
            $bankCode,
            null === $statusCode ? '' : sprintf(', status %d', $statusCode),
            null === $this->oauthError ? '' : sprintf(', error %s', $this->oauthError),
        ));
    }

    public function getBankCode(): string
    {
        return $this->bankCode;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getOauthError(): ?string
    {
        return $this->oauthError;
    }
}
