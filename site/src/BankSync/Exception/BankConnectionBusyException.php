<?php

declare(strict_types=1);

namespace App\BankSync\Exception;

/**
 * Токены подключения дольше таймаута меняет другой процесс. Временное: сообщение
 * повторяется ретраем транспорта.
 */
final class BankConnectionBusyException extends \RuntimeException
{
    public function __construct(string $connectionId)
    {
        parent::__construct(sprintf('Bank connection %s tokens are being changed by another process.', $connectionId));
    }
}
