<?php

declare(strict_types=1);

namespace App\Api\Exception;

final class ApiKeyPermissionsConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Настройки ключа изменились. Обновите страницу и повторите изменение.');
    }
}
