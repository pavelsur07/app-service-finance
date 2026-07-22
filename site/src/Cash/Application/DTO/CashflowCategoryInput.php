<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;

/**
 * Вход для создания или изменения статьи ДДС.
 *
 * null означает «не менять» — при создании применяются значения по умолчанию сущности.
 * Поэтому очистить описание или вынести статью в корень через этот вход нельзя.
 */
final readonly class CashflowCategoryInput
{
    public function __construct(
        public ?string $id = null,
        public ?string $name = null,
        public ?string $parentId = null,
        public ?string $description = null,
        public ?CashflowCategoryStatus $status = null,
        public ?int $sort = null,
        public ?CashflowFlowKind $flowKind = null,
    ) {
    }
}
