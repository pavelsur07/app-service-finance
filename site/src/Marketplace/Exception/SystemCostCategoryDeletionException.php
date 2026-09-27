<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class SystemCostCategoryDeletionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Невозможно удалить системную категорию');
    }
}
