<?php

declare(strict_types=1);

namespace App\Budget\Application\DTO;

/**
 * `apply` false — только предпросмотр. Перезапись заполненных ячеек — только с `confirmOverwrite`.
 */
final readonly class FillBudgetCellsCommand
{
    public function __construct(
        public string $companyId,
        public string $versionId,
        public BudgetFillRequest $request,
        public bool $apply,
        public bool $confirmOverwrite,
    ) {
    }
}
