<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Вход через банк в этой среде не настроен: нет `client_id`, `client_secret` или файла
 * сертификата подписи токенов. На проде до запуска банка — штатное состояние.
 *
 * В сообщении только имя переменной окружения: ни секрета, ни пути.
 */
final class BankAuthorizationNotConfiguredException extends \RuntimeException
{
    public function __construct(string $bankCode, string $problem)
    {
        parent::__construct(sprintf('Bank "%s" authorization is not configured: %s.', $bankCode, $problem));
    }
}
