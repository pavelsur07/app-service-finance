<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesRnpDailyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WildberriesRnpDailyRepository::class)]
#[ORM\Table(name: 'wildberries_rnp_daily')]
#[ORM\UniqueConstraint(name: 'uniq_wb_rnp_company_date_sku', columns: ['company_id', 'date', 'sku'])]
#[ORM\Index(name: 'idx_wb_rnp_company_date', columns: ['company_id', 'date'])]
#[ORM\Index(name: 'idx_wb_rnp_company_sku', columns: ['company_id', 'sku'])]
#[ORM\Index(name: 'idx_wb_rnp_company_brand', columns: ['company_id', 'brand'])]
#[ORM\Index(name: 'idx_wb_rnp_company_category', columns: ['company_id', 'category'])]
class WildberriesRnpDaily
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 128)]
    private string $sku;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $ordersCountSpp = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $ordersSumSppMinor = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $salesCountSpp = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $salesSumSppMinor = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $adCostSumMinor = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $buyoutRate = '0.00';

    #[ORM\Column(type: Types::INTEGER)]
    private int $cogsSumSppMinor = 0;

    public function __construct(string $id, Company $company, \DateTimeImmutable $date, string $sku)
    {
        Assert::uuid($id);

        $this->id = $id;
        $this->company = $company;
        $this->date = $date->setTime(0, 0);
        $this->sku = $sku;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): void
    {
        $this->company = $company;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->date = $date->setTime(0, 0);
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): void
    {
        $this->sku = $sku;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): void
    {
        $this->brand = $brand;
    }

    public function getOrdersCountSpp(): int
    {
        return $this->ordersCountSpp;
    }

    public function setOrdersCountSpp(int $ordersCountSpp): void
    {
        $this->ordersCountSpp = $ordersCountSpp;
    }

    public function getOrdersSumSppMinor(): int
    {
        return $this->ordersSumSppMinor;
    }

    public function setOrdersSumSppMinor(int $ordersSumSppMinor): void
    {
        $this->ordersSumSppMinor = $ordersSumSppMinor;
    }

    public function getSalesCountSpp(): int
    {
        return $this->salesCountSpp;
    }

    public function setSalesCountSpp(int $salesCountSpp): void
    {
        $this->salesCountSpp = $salesCountSpp;
    }

    public function getSalesSumSppMinor(): int
    {
        return $this->salesSumSppMinor;
    }

    public function setSalesSumSppMinor(int $salesSumSppMinor): void
    {
        $this->salesSumSppMinor = $salesSumSppMinor;
    }

    public function getAdCostSumMinor(): int
    {
        return $this->adCostSumMinor;
    }

    public function setAdCostSumMinor(int $adCostSumMinor): void
    {
        $this->adCostSumMinor = $adCostSumMinor;
    }

    public function getBuyoutRate(): string
    {
        return $this->buyoutRate;
    }

    public function setBuyoutRate(string $buyoutRate): void
    {
        $this->buyoutRate = $buyoutRate;
    }

    public function getCogsSumSppMinor(): int
    {
        return $this->cogsSumSppMinor;
    }

    public function setCogsSumSppMinor(int $cogsSumSppMinor): void
    {
        $this->cogsSumSppMinor = $cogsSumSppMinor;
    }
}
