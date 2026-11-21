<?php

declare(strict_types=1);

namespace App\Cash\Exception;

final class PaymentIntakeAccessDeniedException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Нет права записи платежей компании.');
    }
}
