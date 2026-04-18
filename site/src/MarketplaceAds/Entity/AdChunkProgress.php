<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\MarketplaceAds\Repository\AdChunkProgressRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Ledger-запись об успешно выгруженном чанке AdLoadJob'а.
 *
 * UNIQUE (job_id, date_from, date_to) превращает учёт chunks_completed в
 * идемпотентную операцию: INSERT ... ON CONFLICT DO NOTHING вернёт 1 строку
 * только при первой вставке, на retry'ях (оркестратор / Messenger) — 0.
 * Это развязывает детекцию retry от состояния AdRawDocument (которое может
 * быть existing по другим причинам: CLI-preload, новый jobId на уже
 * загруженный период).
 *
 * Запись читается только в тестах и для поддержки — hot-path использует
 * лишь INSERT через {@see AdChunkProgressRepository::tryMarkCompleted()}.
 */
#[ORM\Entity(repositoryClass: AdChunkProgressRepository::class)]
#[ORM\Table(name: 'marketplace_ad_chunk_progress')]
#[ORM\UniqueConstraint(name: 'uniq_ad_chunk_progress_job_range', columns: ['job_id', 'date_from', 'date_to'])]
#[ORM\Index(columns: ['company_id'], name: 'idx_ad_chunk_progress_company')]
class AdChunkProgress
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $jobId;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $completedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $jobId,
        string $companyId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($jobId);
        Assert::uuid($companyId);

        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        if ($dateFrom > $dateTo) {
            throw new \DomainException('dateFrom не может быть позже dateTo.');
        }

        $this->jobId = $jobId;
        $this->companyId = $companyId;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->completedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
