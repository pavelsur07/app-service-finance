<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

/**
 * Итог загрузки выписки для журнала импорта.
 */
final readonly class StatementImportSummary
{
    /**
     * @param string|null $failure класс исключения, прервавшего загрузку; null — загрузка завершена
     */
    public function __construct(
        public int $accountsFound,
        public int $accountsMatched,
        public int $created,
        public int $skippedDuplicates,
        public int $errors,
        public ?string $failure = null,
    ) {
    }
}
