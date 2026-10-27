<?php

declare(strict_types=1);

namespace App\BankSync\MessageHandler;

use App\Cash\Application\DTO\StatementImportSummary;

/**
 * Счётчики одной загрузки. Копятся по дням: при сбое на середине журнал получает то, что
 * уже записано.
 */
final class SyncCounters
{
    public int $accountsFound = 0;
    public int $accountsMatched = 0;
    /** Счета банка, которые есть в ответе, но не грузятся: не привязаны или закрыты в банке. */
    public int $accountsUnlinked = 0;
    public int $created = 0;
    public int $skippedDuplicates = 0;
    public int $errors = 0;

    public function summary(?string $failure = null): StatementImportSummary
    {
        return new StatementImportSummary(
            $this->accountsFound,
            $this->accountsMatched,
            $this->created,
            $this->skippedDuplicates,
            $this->errors,
            $failure,
        );
    }

    /**
     * @return array<string, int>
     */
    public function toLogContext(): array
    {
        return [
            'accounts_found' => $this->accountsFound,
            'accounts_matched' => $this->accountsMatched,
            'accounts_not_loaded' => $this->accountsUnlinked,
            'created' => $this->created,
            'skipped_duplicates' => $this->skippedDuplicates,
            'errors' => $this->errors,
        ];
    }
}
