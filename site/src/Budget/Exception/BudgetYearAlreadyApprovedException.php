<?php

declare(strict_types=1);

namespace App\Budget\Exception;

final class BudgetYearAlreadyApprovedException extends \RuntimeException
{
    public function __construct(int $year, ?string $approvedName)
    {
        parent::__construct(null === $approvedName
            ? sprintf('На %d год уже утверждён другой вариант. Сначала верните его в черновик.', $year)
            : sprintf('На %d год уже утверждён «%s». Сначала верните его в черновик.', $year, $approvedName));
    }
}
