<?php

declare(strict_types=1);

namespace App\Balance\DTO;

/**
 * Учётный счёт, который шаблон заводит на конечной статье новой компании.
 */
final readonly class BalanceStructureTemplateAccount
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $allowNegative = false,
    ) {
    }
}
