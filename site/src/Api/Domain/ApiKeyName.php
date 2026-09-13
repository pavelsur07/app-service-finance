<?php

declare(strict_types=1);

namespace App\Api\Domain;

use App\Api\Exception\InvalidApiKeyNameException;

final class ApiKeyName
{
    public static function normalize(string $name): string
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 255) {
            throw new InvalidApiKeyNameException();
        }

        return $name;
    }
}
