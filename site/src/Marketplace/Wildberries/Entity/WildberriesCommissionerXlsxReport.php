<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesCommissionerXlsxReportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WildberriesCommissionerXlsxReportRepository::class)]
#[ORM\Table(name: 'wildberries_commissioner_xlsx_reports')]
#[ORM\Index(name: 'idx_wb_commissioner_xlsx_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_wb_commissioner_xlsx_status', columns: ['status'])]
class WildberriesCommissionerXlsxReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 255)]
    private string $storagePath;

    #[ORM\Column(length: 64)]
    private string $fileHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $headersHash = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $formatStatus = null;

    #[ORM\Column(length: 32)]
    private string $status = 'uploaded';

    #[ORM\Column(type: 'integer')]
    private int $rowsTotal = 0;

    #[ORM\Column(type: 'integer')]
    private int $rowsParsed = 0;

    #[ORM\Column(type: 'integer')]
    private int $errorsCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $warningsCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $errorsJson = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $warningsJson = null;

    public function __construct(string $id, Company $company, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->company = $company;
        $this->createdAt = $createdAt;
    }

    public function getId(): string
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

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): void
    {
        $this->periodStart = $periodStart;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): void
    {
        $this->periodEnd = $periodEnd;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): void
    {
        $this->originalFilename = $originalFilename;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): void
    {
        $this->storagePath = $storagePath;
    }

    public function getFileHash(): string
    {
        return $this->fileHash;
    }

    public function setFileHash(string $fileHash): void
    {
        $this->fileHash = $fileHash;
    }

    public function getHeadersHash(): ?string
    {
        return $this->headersHash;
    }

    public function setHeadersHash(?string $headersHash): void
    {
        $this->headersHash = $headersHash;
    }

    public function getFormatStatus(): ?string
    {
        return $this->formatStatus;
    }

    public function setFormatStatus(?string $formatStatus): void
    {
        $this->formatStatus = $formatStatus;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getRowsTotal(): int
    {
        return $this->rowsTotal;
    }

    public function setRowsTotal(int $rowsTotal): void
    {
        $this->rowsTotal = $rowsTotal;
    }

    public function getRowsParsed(): int
    {
        return $this->rowsParsed;
    }

    public function setRowsParsed(int $rowsParsed): void
    {
        $this->rowsParsed = $rowsParsed;
    }

    public function getErrorsCount(): int
    {
        return $this->errorsCount;
    }

    public function setErrorsCount(int $errorsCount): void
    {
        $this->errorsCount = $errorsCount;
    }

    public function getWarningsCount(): int
    {
        return $this->warningsCount;
    }

    public function setWarningsCount(int $warningsCount): void
    {
        $this->warningsCount = $warningsCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?\DateTimeImmutable $processedAt): void
    {
        $this->processedAt = $processedAt;
    }

    public function getErrorsJson(): ?array
    {
        return $this->errorsJson;
    }

    public function setErrorsJson(?array $errorsJson): void
    {
        $this->errorsJson = $errorsJson;
    }

    public function getWarningsJson(): ?array
    {
        return $this->warningsJson;
    }

    public function setWarningsJson(?array $warningsJson): void
    {
        $this->warningsJson = $warningsJson;
    }
}
