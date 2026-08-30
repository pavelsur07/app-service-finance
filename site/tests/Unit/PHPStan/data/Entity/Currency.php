<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Entity;

/** Фикстура: справочник платформы без поля компании. */
final class Currency
{
    private string $code = '';

    public function code(): string
    {
        return $this->code;
    }
}
