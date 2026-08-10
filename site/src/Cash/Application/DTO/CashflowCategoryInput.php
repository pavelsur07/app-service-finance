<?php

declare(strict_types=1);

namespace App\Cash\Application\DTO;

use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;

/**
 * Вход для создания или изменения статьи ДДС.
 *
 * null означает «не менять» — при создании применяются значения по умолчанию сущности.
 * Для parentId явный parentIdProvided=true отличает «поле не передано» от «перенести в root».
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
        public bool $parentIdProvided = false,
    ) {
    }
}
