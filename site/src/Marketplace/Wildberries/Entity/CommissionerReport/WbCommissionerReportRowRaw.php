<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity\CommissionerReport;

use App\Entity\Company;
use App\Marketplace\Wildberries\CommissionerReport\Repository\WbCommissionerReportRowRawRepository;
use App\Marketplace\Wildberries\Entity\WildberriesCommissionerXlsxReport;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: WbCommissionerReportRowRawRepository::class)]
#[ORM\Table(name: 'wildberries_commissioner_report_rows_raw')]
#[ORM\UniqueConstraint(name: 'uniq_wb_commissioner_report_row_report_index', columns: ['report_id', 'row_index'])]
#[ORM\Index(name: 'idx_wb_commissioner_report_row_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_wb_commissioner_report_row_report', columns: ['report_id'])]
class WbCommissionerReportRowRaw
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: WildberriesCommissionerXlsxReport::class, cascade: ['persist'])]
    #[ORM\JoinColumn(name: 'report_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private WildberriesCommissionerXlsxReport $report;

    #[ORM\Column(name: 'row_index', type: 'integer')]
    private int $rowIndex;

    #[ORM\Column(name: 'data_json', type: 'json')]
    private array $dataJson = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        Company $company,
        WildberriesCommissionerXlsxReport $report,
        int $rowIndex,
        array $dataJson,
        ?\DateTimeImmutable $createdAt = null
    ) {
        Assert::uuid($id);
        $this->id = $id;
        $this->company = $company;
        $this->report = $report;
        $this->rowIndex = $rowIndex;
        $this->dataJson = $dataJson;
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

    public function getRowIndex(): int
    {
        return $this->rowIndex;
    }

    public function setRowIndex(int $rowIndex): self
    {
        $this->rowIndex = $rowIndex;

        return $this;
    }

    public function getDataJson(): array
    {
        return $this->dataJson;
    }

    public function setDataJson(array $dataJson): self
    {
        $this->dataJson = $dataJson;

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
