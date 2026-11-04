<?php

declare(strict_types=1);

namespace App\Budget\Exception;

use App\Budget\Enum\BudgetVersionStatus;

final class InvalidBudgetVersionTransitionException extends \RuntimeException
{
    public function __construct(BudgetVersionStatus $from, string $action)
    {
        parent::__construct(sprintf('Нельзя %s вариант в статусе «%s».', $action, $from->label()));
    }
}
