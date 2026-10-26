<?php

declare(strict_types=1);

namespace App\Cash\Exception;

/**
 * Денежный счёт или журнал импорта не найден в компании.
 */
final class StatementImportTargetNotFoundException extends \RuntimeException
{
    public static function moneyAccount(string $moneyAccountId): self
    {
        return new self(sprintf('Money account %s not found in company.', $moneyAccountId));
    }

    public static function importLog(string $importLogId): self
    {
        return new self(sprintf('Import log %s not found in company.', $importLogId));
    }
}
