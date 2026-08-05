<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;

/**
 * Узел переносимого дерева категорий ОПиУ — источник импорта, отвязанный от того,
 * откуда дерево пришло (другая компания или файл из другого аккаунта).
 *
 * Узлы всегда идут плоским списком в DFS pre-order: родитель раньше потомка.
 * `parent` ссылается на узел, созданный ранее по этому же списку.
 *
 * `key` — идентификатор узла только внутри одного дерева-источника. Это НЕ id
 * категории: id из чужого аккаунта не имеют смысла в целевой компании и не
 * должны туда попадать. Новым категориям id выдаётся при создании.
 */
final readonly class PLCategoryTreeNode
{
    public function __construct(
        public string $key,
        public ?self $parent,
        public string $name,
        public ?string $code,
        public PLCategoryType $type,
        public PLValueFormat $format,
        public PLFlow $flow,
        public PLExpenseType $expenseType,
        public string $weightInParent,
        public bool $isVisible,
        public ?string $formula,
        public ?int $calcOrder,
        public int $sortOrder,
    ) {
    }
}
