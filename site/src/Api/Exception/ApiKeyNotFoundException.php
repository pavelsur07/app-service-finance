<?php

declare(strict_types=1);

namespace App\Api\Exception;

final class ApiKeyNotFoundException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('API-ключ не найден.');
    }
}
