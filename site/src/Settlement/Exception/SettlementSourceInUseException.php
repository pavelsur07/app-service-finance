<?php

declare(strict_types=1);

namespace App\Settlement\Exception;

final class SettlementSourceInUseException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Источник участвует во взаиморасчётах. Сначала отмените связанные расчётные документы.');
    }
}
