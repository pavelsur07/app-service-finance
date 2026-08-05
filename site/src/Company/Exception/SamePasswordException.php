<?php

declare(strict_types=1);

namespace App\Company\Exception;

final class SamePasswordException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Новый пароль должен отличаться от текущего.');
    }
}
