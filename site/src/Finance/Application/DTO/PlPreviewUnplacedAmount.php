<?php

declare(strict_types=1);

namespace App\Finance\Application\DTO;

use App\Finance\Enum\PlPreviewUnplacedReason;

/**
 * Сумма, которая уйдёт в документ ОПиУ, но не попадёт в отчёт: статья не найдена в компании,
 * не является строкой ввода или не имеет кода. Значения — в знаке входа, по колонкам.
 */
final readonly class PlPreviewUnplacedAmount
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public string $categoryId,
        public ?string $categoryName,
        public PlPreviewUnplacedReason $reason,
        public array $values,
    ) {
    }
}
