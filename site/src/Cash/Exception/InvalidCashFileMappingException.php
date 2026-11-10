<?php

declare(strict_types=1);

namespace App\Cash\Exception;

use App\Shared\Domain\Exception\UserFacingException;

final class InvalidCashFileMappingException extends \RuntimeException implements UserFacingException
{
    public function __construct(string $message = 'Некорректный mapping: разрешены только известные поля со строковым значением или null.')
    {
        parent::__construct($message);
    }
}
