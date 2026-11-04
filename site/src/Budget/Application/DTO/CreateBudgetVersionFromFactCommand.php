<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * Новый черновик года, заполненный фактом ОПиУ прошлого года по всем активным парам.
 * `versionId` задаёт вызывающий (скрытый UUIDv7 формы) — ключ повтора.
 */
final readonly class CreateBudgetVersionFromFactCommand
{
    public function __construct(
        public string $versionId,
        public string $companyId,
        public string $name,
        public int $year,
    ) {
    }
}
