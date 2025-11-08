<?php

declare(strict_types=1);

namespace App\Marketplace\Wildberries\Entity;

use App\Entity\Company;
use App\Marketplace\Wildberries\Repository\WildberriesImportLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WildberriesImportLogRepository::class)]
#[ORM\Table(name: 'wildberries_import_log')]
#[ORM\Index(name: 'idx_wb_import_log_company_started', columns: ['company_id', 'started_at'])]
#[ORM\Index(name: 'idx_wb_import_log_company_finished', columns: ['company_id', 'finished_at'], options: ['order' => ['finished_at' => 'DESC']])]
#[ORM\Index(name: 'idx_wb_import_log_source', columns: ['source'])]
class WildberriesImportLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 64)]
    private string $source = 'wb_report_detail';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $createdCount = 0;

    #[ORM\Column(type: 'integer')]
    private int $skippedDuplicates = 0;

    #[ORM\Column(type: 'integer')]
    private int $errorsCount = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $preview = false;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $userId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fileName = null;

    public function __construct(string $id, Company $company, \DateTimeImmutable $startedAt)
    {
        $this->id = $id;
        $this->company = $company;
        $this->startedAt = $startedAt;
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    public function getCreatedCount(): int
    {
        return $this->createdCount;
    }

    public function setCreatedCount(int $v): void
    {
        $this->createdCount = $v;
    }

    public function getSkippedDuplicates(): int
    {
        return $this->skippedDuplicates;
    }

    public function setSkippedDuplicates(int $v): void
    {
        $this->skippedDuplicates = $v;
    }

    public function getErrorsCount(): int
    {
        return $this->errorsCount;
    }

    public function setErrorsCount(int $v): void
    {
        $this->errorsCount = $v;
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function setPreview(bool $v): void
    {
        $this->preview = $v;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $v): void
    {
        $this->userId = $v;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $v): void
    {
        $this->fileName = $v;
    }
}
