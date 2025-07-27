<?php

namespace App\Entity\Ozon;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\Ozon\OzonProductStockRepository;

#[ORM\Entity(repositoryClass: OzonProductStockRepository::class)]
#[ORM\Table(name: '`ozon_product_stocks`')]
class OzonProductStock
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: OzonProduct::class)]
    #[ORM\JoinColumn(nullable: false)]
    private OzonProduct $product;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(type: 'integer')]
    private int $qty = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, OzonProduct $product, Company $company)
    {
        $this->id = $id;
        $this->product = $product;
        $this->company = $company;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getProduct(): OzonProduct
    {
        return $this->product;
    }

    public function setProduct(OzonProduct $product): void
    {
        $this->product = $product;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function setQty(int $qty): void
    {
        $this->qty = $qty;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
