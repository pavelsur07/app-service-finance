<?php

declare(strict_types=1);

namespace App\Api\Exception;

final class ApiKeyOwnerRequiredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Управлять API может только владелец компании.');
    }
}
