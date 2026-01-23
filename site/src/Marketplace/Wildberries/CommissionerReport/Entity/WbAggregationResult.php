<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\CommissionerReport\Entity;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbAggregationResultRepository;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WbAggregationResultRepository::class)]
#[ORM\Table(name: 'wildberries_commissioner_aggregation_results')]
#[ORM\Index(name: 'idx_wb_commissioner_agg_company_report', columns: ['company_id', 'report_id'])]
#[ORM\Index(name: 'idx_wb_commissioner_agg_company_report_status', columns: ['company_id', 'report_id', 'status'])]
class WbAggregationResult
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: WildberriesCommissionerXlsxReport::class)]
    #[ORM\JoinColumn(name: 'report_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WildberriesCommissionerXlsxReport $report;

    #[ORM\ManyToOne(targetEntity: WbCostType::class)]
    #[ORM\JoinColumn(name: 'cost_type_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WbCostType $costType = null;

    #[ORM\ManyToOne(targetEntity: WbDimensionValue::class)]
    #[ORM\JoinColumn(name: 'dimension_value_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WbDimensionValue $dimensionValue = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        Company $company,
        WildberriesCommissionerXlsxReport $report,
        string $amount,
        string $status,
        ?WbCostType $costType = null,
        ?WbDimensionValue $dimensionValue = null,
        ?\DateTimeImmutable $createdAt = null
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->report = $report;
        $this->amount = $amount;
        $this->status = $status;
        $this->costType = $costType;
        $this->dimensionValue = $dimensionValue;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function setCompany(Company $company): self
    {
        $this->company = $company;

        return $this;
    }

    public function getReport(): WildberriesCommissionerXlsxReport
    {
        return $this->report;
    }

    public function setReport(WildberriesCommissionerXlsxReport $report): self
    {
        $this->report = $report;

        return $this;
    }

    public function getCostType(): ?WbCostType
    {
        return $this->costType;
    }

    public function setCostType(?WbCostType $costType): self
    {
        $this->costType = $costType;

        return $this;
    }

    public function getDimensionValue(): ?WbDimensionValue
    {
        return $this->dimensionValue;
    }

    public function setDimensionValue(?WbDimensionValue $dimensionValue): self
    {
        $this->dimensionValue = $dimensionValue;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
