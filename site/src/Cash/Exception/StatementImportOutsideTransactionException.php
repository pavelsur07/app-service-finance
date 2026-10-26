<?php

declare(strict_types=1);

namespace App\Cash\Exception;

/**
 * Импорт дня выписки вызван без открытой транзакции: строки, пересчёт остатков и курсор
 * вызывающего должны сохраниться вместе.
 */
final class StatementImportOutsideTransactionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bank statement day import must run inside the caller transaction.');
    }
}
