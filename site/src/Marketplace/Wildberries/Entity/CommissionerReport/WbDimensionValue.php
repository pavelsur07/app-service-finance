<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity\CommissionerReport;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbDimensionValueRepository;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WbDimensionValueRepository::class)]
#[ORM\Table(name: 'wildberries_commissioner_dimension_values')]
#[ORM\UniqueConstraint(
    name: 'uniq_wb_commissioner_dimension_value_company_report_key_value',
    columns: ['company_id', 'report_id', 'dimension_key', 'normalized_value']
)]
#[ORM\Index(name: 'idx_wb_commissioner_dimension_value_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_wb_commissioner_dimension_value_report', columns: ['report_id'])]
class WbDimensionValue
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

    #[ORM\Column(name: 'dimension_key', length: 64)]
    private string $dimensionKey;

    #[ORM\Column(type: 'text')]
    private string $value;

    #[ORM\Column(name: 'normalized_value', type: 'text')]
    private string $normalizedValue;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $occurrences = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        Company $company,
        WildberriesCommissionerXlsxReport $report,
        string $dimensionKey,
        string $value,
        string $normalizedValue,
        ?\DateTimeImmutable $createdAt = null
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->report = $report;
        $this->dimensionKey = $dimensionKey;
        $this->value = $value;
        $this->normalizedValue = $normalizedValue;
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

    public function getDimensionKey(): string
    {
        return $this->dimensionKey;
    }

    public function setDimensionKey(string $dimensionKey): self
    {
        $this->dimensionKey = $dimensionKey;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getNormalizedValue(): string
    {
        return $this->normalizedValue;
    }

    public function setNormalizedValue(string $normalizedValue): self
    {
        $this->normalizedValue = $normalizedValue;

        return $this;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function setOccurrences(int $occurrences): self
    {
        $this->occurrences = $occurrences;

        return $this;
    }

    public function incrementOccurrences(int $by = 1): self
    {
        $this->occurrences += $by;

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
