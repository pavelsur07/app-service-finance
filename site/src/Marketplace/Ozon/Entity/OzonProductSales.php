<?php

namespace App\Marketplace\Ozon\Entity;

use App\Entity\Company;
use App\Marketplace\Ozon\Repository\OzonProductSalesRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OzonProductSalesRepository::class)]
#[ORM\Table(name: '`ozon_product_sales`')]
#[ORM\UniqueConstraint(name: 'uniq_sales_period', columns: ['product_id', 'company_id', 'date_from', 'date_to'])]
class OzonProductSales
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
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $dateTo;

    public function __construct(string $id, OzonProduct $product, Company $company, \DateTimeImmutable $dateFrom, \DateTimeImmutable $dateTo)
    {
        $this->id = $id;
        $this->product = $product;
        $this->company = $company;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
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

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function setDateFrom(\DateTimeImmutable $dateFrom): void
    {
        $this->dateFrom = $dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function setDateTo(\DateTimeImmutable $dateTo): void
    {
        $this->dateTo = $dateTo;
    }
}
