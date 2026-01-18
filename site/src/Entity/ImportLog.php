<?php

namespace App\Entity;

use App\Cash\Repository\Import\ImportLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportLogRepository::class)]
#[ORM\Table(name: 'import_log')]
#[ORM\Index(name: 'idx_import_log_company_started', columns: ['company_id', 'started_at'])]
#[ORM\Index(name: 'idx_import_log_company_finished', columns: ['company_id', 'finished_at'], options: ['order' => ['finished_at' => 'DESC']])]
class ImportLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: null)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 64)]
    private string $source;

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

    public function __construct(string $id)
    {
        $this->id = $id;
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

    public function setCreatedCount(int $createdCount): void
    {
        $this->createdCount = $createdCount;
    }

    public function getSkippedDuplicates(): int
    {
        return $this->skippedDuplicates;
    }

    public function setSkippedDuplicates(int $skippedDuplicates): void
    {
        $this->skippedDuplicates = $skippedDuplicates;
    }

    public function getErrorsCount(): int
    {
        return $this->errorsCount;
    }

    public function setErrorsCount(int $errorsCount): void
    {
        $this->errorsCount = $errorsCount;
    }

    public function isPreview(): bool
    {
        return $this->preview;
    }

    public function setPreview(bool $preview): void
    {
        $this->preview = $preview;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): void
    {
        $this->userId = $userId;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function setFileName(?string $fileName): void
    {
        $this->fileName = $fileName;
    }
}
