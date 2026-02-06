<?php

declare(strict_types=1);

namespace App\Deals\DTO;

use App\Deals\Entity\ChargeType;
use Symfony\Component\Validator\Constraints as Assert;

final class ChargeTypeFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public ?string $code = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    public bool $isActive = true;

    public static function fromEntity(ChargeType $entity): self
    {
        $data = new self();
        $data->code = $entity->getCode();
        $data->name = $entity->getName();
        $data->isActive = $entity->isActive();

        return $data;
    }

    public function applyToEntity(ChargeType $entity): void
    {
        $entity->setCode((string) $this->code)
            ->setName((string) $this->name)
            ->setIsActive($this->isActive);
    }
}
