<?php

declare(strict_types=1);

namespace App\Budget\Exception;

final class BudgetApprovalForbiddenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Утверждает вариант и возвращает утверждённый в черновик только владелец компании.');
    }
}
