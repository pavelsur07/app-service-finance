<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Enum\FeatureType;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Billing\Repository\FeatureRepository::class)]
#[ORM\Table(name: 'billing_feature')]
#[ORM\UniqueConstraint(name: 'uniq_billing_feature_code', columns: ['code'])]
final class Feature
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $code;

    #[ORM\Column(type: 'string', enumType: FeatureType::class)]
    private FeatureType $type;

    #[ORM\Column(type: 'string')]
    private string $name;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $description;

    public function __construct(
        string $id,
        string $code,
        FeatureType $type,
        string $name,
        ?string $description,
    ) {
        $this->id = $id;
        $this->code = $code;
        $this->type = $type;
        $this->name = $name;
        $this->description = $description;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getType(): FeatureType
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
