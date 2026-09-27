<?php

declare(strict_types=1);

namespace App\Marketplace\Exception;

final class CostMappingPLCategoryNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Категория ОПиУ не найдена в текущей компании.');
    }
}
