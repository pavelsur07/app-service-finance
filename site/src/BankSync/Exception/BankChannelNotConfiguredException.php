<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Канал до банка не настроен: нет клиентского сертификата, файл не читается, ключ не
 * подходит. Инцидент конфигурации, а не банка — повтор не поможет.
 *
 * В сообщении только имя переменной окружения: ни пути к ключу, ни пароля.
 */
final class BankChannelNotConfiguredException extends \RuntimeException
{
    public function __construct(string $bankCode, string $problem)
    {
        parent::__construct(sprintf('Bank "%s" TLS channel is not configured: %s.', $bankCode, $problem));
    }
}
