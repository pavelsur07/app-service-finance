<?php

declare(strict_types=1);

namespace App\Budget\Exception;

final class BudgetFillOverwriteUnconfirmedException extends \RuntimeException
{
    public function __construct(int $overwrite)
    {
        parent::__construct(sprintf('Помощник перезапишет заполненные ячейки (%d) — подтвердите перезапись.', $overwrite));
    }
}
