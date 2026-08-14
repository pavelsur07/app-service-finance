<?php

declare(strict_types=1);

namespace App\Balance\Exception;

final class BalanceCategoryNotFoundException extends \DomainException
{
    public function __construct(string $categoryId)
    {
        parent::__construct(sprintf('Категория баланса %s не найдена.', $categoryId));
    }
}
