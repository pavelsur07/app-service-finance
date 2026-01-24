<?php

namespace App\Marketplace\Ozon\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: '`ozon_products`')]
class OzonProduct
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\Column(length: 255)]
    private string $ozonSku;

    #[ORM\Column(length: 255)]
    private string $manufacturerSku;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'float')]
    private float $price;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $imageUrl;

    #[ORM\Column(type: 'boolean')]
    private bool $archived = false;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    public function __construct(string $id, Company $company)
    {
        $this->id = $id;
        $this->company = $company;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getOzonSku(): string
    {
        return $this->ozonSku;
    }

    public function setOzonSku(string $ozonSku): void
    {
        $this->ozonSku = $ozonSku;
    }

    public function getManufacturerSku(): string
    {
        return $this->manufacturerSku;
    }

    public function setManufacturerSku(string $manufacturerSku): void
    {
        $this->manufacturerSku = $manufacturerSku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function setPrice(float $price): void
    {
        $this->price = $price;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): void
    {
        $this->archived = $archived;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }
}
