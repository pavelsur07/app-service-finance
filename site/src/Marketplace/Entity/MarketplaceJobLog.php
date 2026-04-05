<?php

declare(strict_types=1);

namespace App\Marketplace\Entity;

use App\Marketplace\Enum\JobStatus;
use App\Marketplace\Enum\JobType;
use App\Marketplace\Repository\MarketplaceJobLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Лог асинхронных задач модуля Marketplace.
 * Хранит последний результат каждой операции для отображения в UI.
 *
 * summary — краткая статистика: {created: 3, skipped: 0, errors: 1}
 * details — список проблемных записей: [{sku: '123', reason: 'not found'}, ...]
 *
 * Контракт baseline/target:
 * - processing run = одна попытка полного проведения raw document в рамках pipeline;
 * - step run = один шаг внутри processing run (sales|returns|costs);
 * - failed step => processing run считается неуспешным.
 */
#[ORM\Entity(repositoryClass: MarketplaceJobLogRepository::class)]
#[ORM\Table(name: 'marketplace_job_logs')]
#[ORM\Index(columns: ['company_id', 'job_type', 'started_at'], name: 'idx_job_log_company_type')]
class MarketplaceJobLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', enumType: JobType::class)]
    private JobType $jobType;

    #[ORM\Column(type: 'string', enumType: JobStatus::class)]
    private JobStatus $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Краткая статистика: {created: 3, skipped: 0, errors: 1} */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $summary = null;

    /** Детали проблемных записей: [{sku: '123', reason: 'not found'}, ...] */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $details = null;

    public function __construct(string $id, string $companyId, JobType $jobType)
    {
        Assert::uuid($id);
        Assert::uuid($companyId);

        $this->id        = $id;
        $this->companyId = $companyId;
        $this->jobType   = $jobType;
        $this->status    = JobStatus::RUNNING;
        $this->startedAt = new \DateTimeImmutable();
    }

    public function complete(array $summary, array $details = []): void
    {
        $this->status     = JobStatus::DONE;
        $this->finishedAt = new \DateTimeImmutable();
        $this->summary    = $summary;
        $this->details    = $details;
    }

    public function fail(string $errorMessage): void
    {
        $this->status     = JobStatus::FAILED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->summary    = ['error' => $errorMessage];
        $this->details    = [];
    }

    public function getId(): string { return $this->id; }
    public function getCompanyId(): string { return $this->companyId; }
    public function getJobType(): JobType { return $this->jobType; }
    public function getStatus(): JobStatus { return $this->status; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getSummary(): ?array { return $this->summary; }
    public function getDetails(): ?array { return $this->details; }

    public function hasErrors(): bool
    {
        if ($this->status === JobStatus::FAILED) {
            return true;
        }

        return ($this->summary['errors'] ?? 0) > 0;
    }
}
