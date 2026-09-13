<?php

declare(strict_types=1);

namespace App\Api\Exception;

final class InvalidApiKeyPermissionsException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Неизвестные права или раздел API ещё не подключён.');
    }
}
