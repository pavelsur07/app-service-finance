<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class InvalidCurrentPasswordException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Текущий пароль указан неверно.');
    }
}
