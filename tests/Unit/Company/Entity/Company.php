<?php

declare(strict_types=1);

namespace App\Company\Entity;

final class Company
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }
}
