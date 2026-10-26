<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

final readonly class BankStatementDayResult
{
    /**
     * @param int $skippedDuplicates строки, уже загруженные раньше (в том числе удалённые пользователем) или
     *                               повторённые внутри дня
     * @param int $errors строки не в валюте счёта
     */
    public function __construct(
        public int $created,
        public int $skippedDuplicates,
        public int $errors,
    ) {
    }
}
