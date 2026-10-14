<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Глобальный справочник: поля компании нет. */
#[ORM\Entity]
class Tariff
{
    #[ORM\Id, ORM\Column]
    private string $id = '';

    public function getId(): string
    {
        return $this->id;
    }
}
