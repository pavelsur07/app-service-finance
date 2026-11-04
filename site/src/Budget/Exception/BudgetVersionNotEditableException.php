<?php

declare(strict_types=1);

namespace App\Budget\Exception;

use App\Budget\Enum\BudgetVersionStatus;

final class BudgetVersionNotEditableException extends \RuntimeException
{
    public function __construct(BudgetVersionStatus $status)
    {
        parent::__construct(sprintf('Вариант в статусе «%s» не редактируется. Чтобы править, верните его в черновик.', $status->label()));
    }
}
