<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class CostCategoryNotFoundException extends \RuntimeException
{
    public function __construct(string $costCategoryId)
    {
        parent::__construct(sprintf('Категория затрат %s не найдена.', $costCategoryId));
    }
}
