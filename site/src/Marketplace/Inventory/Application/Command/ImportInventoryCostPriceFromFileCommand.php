<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application\Command;

use App\Marketplace\Enum\MarketplaceType;

/**
 * Команда пакетного импорта себестоимости из xls/xlsx файла.
 * Только scalar типы — безопасно для Worker/CLI/сериализации.
 */
final class ImportInventoryCostPriceFromFileCommand
{
    public function __construct(
        public readonly string          $companyId,
        public readonly string          $absoluteFilePath,
        public readonly string          $originalFilename,
        public readonly \DateTimeImmutable $effectiveFrom,
        public readonly MarketplaceType $marketplace,
    ) {
    }
}
