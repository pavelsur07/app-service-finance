<?php

declare(strict_types=1);

namespace App\Budget\Exception;

final class InvalidBudgetPairException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Пара «проект × ЦФО» не найдена или не активна.');
    }
}
