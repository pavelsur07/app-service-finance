<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class ImportPLCategoryTreeResult
{
    /**
     * @param ImportPLCategoryTreeRow[] $created
     * @param ImportPLCategoryTreeRow[] $updated
     * @param list<string> $unresolvedFormulaCodes токены формул, которых не будет в целевой компании после импорта
     * @param list<ImportPLCategoryTreeSimilarRow> $similar статьи шаблона, не созданные из-за похожей статьи компании
     * @param int $companyRoots корневых статей у компании до импорта
     * @param int $recognizedRoots из них опознано корневыми узлами источника (по коду или имени)
     * @param list<ImportPLCategoryTreeRow> $skippedWithSimilar новые потомки похожих статей — не созданы вместе с ними
     */
    public function __construct(
        public array $created,
        public array $updated,
        public int $unchangedCount,
        public array $unresolvedFormulaCodes = [],
        public array $similar = [],
        public int $companyRoots = 0,
        public int $recognizedRoots = 0,
        public array $skippedWithSimilar = [],
    ) {
    }
}
