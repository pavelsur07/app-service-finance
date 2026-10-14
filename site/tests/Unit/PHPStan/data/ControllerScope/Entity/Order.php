<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Order
{
    #[ORM\Id, ORM\Column]
    private string $id = '';

    #[ORM\Column]
    private string $companyId = '';

    public function getId(): string
    {
        return $this->id.$this->companyId;
    }
}
