<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

/**
 * Статья шаблона, которую импорт не создал: у компании уже есть статья с похожим названием.
 */
final readonly class ImportPLCategoryTreeSimilarRow
{
    public function __construct(
        public string $name,
        public ?string $code,
        public string $path,
        public string $existingId,
        public string $existingName,
    ) {
    }
}
