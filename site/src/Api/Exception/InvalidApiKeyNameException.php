<?php

declare(strict_types=1);

namespace App\Api\Exception;

final class InvalidApiKeyNameException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Имя ключа должно содержать от 1 до 255 символов.');
    }
}
