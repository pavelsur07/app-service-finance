<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Команда запуска обработки импорта товаров из XLS.
 * companyId передаётся как string (UUID) — согласно правилам разработки.
 * Controller получает companyId через ActiveCompanyService и передаёт сюда.
 */
final class ImportProductsCommand
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $importId,
    ) {
    }
}
