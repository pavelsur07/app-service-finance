<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Entity;

/** Фикстура: сущность принадлежит компании, репозиторий без docblock-шаблона. */
final class Invoice
{
    private string $companyId = '';

    public function companyId(): string
    {
        return $this->companyId;
    }
}
