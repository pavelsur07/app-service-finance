<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

final readonly class PlPreviewResult
{
    /**
     * @param list<PlPreviewRow> $rows дерево ОПиУ компании в порядке отчёта
     * @param list<PlPreviewUnplacedAmount> $unplaced
     * @param list<string> $warnings ошибки формул дерева
     */
    public function __construct(
        public array $rows,
        public array $unplaced,
        public array $warnings,
    ) {
    }
}
