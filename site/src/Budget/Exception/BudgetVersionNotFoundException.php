<?php

declare(strict_types=1);

namespace App\Budget\Exception;

final class BudgetVersionNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Вариант бюджета не найден.');
    }
}
