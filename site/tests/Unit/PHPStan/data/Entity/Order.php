<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Entity;

/** Фикстура: сущность принадлежит компании. */
final class Order
{
    private string $companyId = '';

    public function companyId(): string
    {
        return $this->companyId;
    }
}
