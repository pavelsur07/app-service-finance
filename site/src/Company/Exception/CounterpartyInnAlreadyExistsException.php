<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class CounterpartyInnAlreadyExistsException extends \RuntimeException
{
    public function __construct(string $inn)
    {
        parent::__construct(sprintf('Контрагент с ИНН %s уже существует.', $inn));
    }
}
