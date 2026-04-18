<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Enum\AdLoadJobStatus;
use App\MarketplaceAds\Repository\AdLoadJobRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Задание на пакетную загрузку рекламных отчётов за период.
 *
 * Прогресс-счётчики (loadedDays/processedDays/failedDays) инкрементируются
 * параллельными воркерами через атомарный `UPDATE ... SET x = x + :delta`
 * в Repository, минуя Doctrine UoW.
 */
#[ORM\Entity(repositoryClass: AdLoadJobRepository::class)]
#[ORM\Table(name: 'marketplace_ad_load_jobs')]
#[ORM\Index(columns: ['company_id'], name: 'idx_ad_load_job_company')]
#[ORM\Index(columns: ['company_id', 'marketplace', 'status'], name: 'idx_ad_load_job_company_marketplace_status')]
#[ORM\Index(columns: ['company_id', 'marketplace', 'date_from', 'date_to'], name: 'idx_ad_load_job_company_marketplace_range')]
class AdLoadJob
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 50, enumType: MarketplaceType::class)]
    private MarketplaceType $marketplace;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;

    #[ORM\Column(type: 'integer')]
    private int $totalDays;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $loadedDays = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $processedDays = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $failedDays = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $chunksTotal = 0;

    #[ORM\Column(type: 'string', length: 20, enumType: AdLoadJobStatus::class, options: ['default' => 'pending'])]
    private AdLoadJobStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);

        // Нормализация до 00:00 — защита от inverted-range при одной и той же дате с разным временем,
        // от off-by-one в diff()->days (который считает полные сутки) и от UTC-смещений.
        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        if ($dateFrom > $dateTo) {
            throw new \DomainException('dateFrom не может быть позже dateTo.');
        }

        $this->companyId = $companyId;
        $this->marketplace = $marketplace;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->totalDays = (int) $dateFrom->diff($dateTo)->days + 1;
        $this->status = AdLoadJobStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markRunning(): void
    {
        if (AdLoadJobStatus::PENDING !== $this->status) {
            throw new \DomainException(sprintf(
                'Запустить можно только задание в статусе PENDING, текущий: %s.',
                $this->status->value,
            ));
        }

        $this->status = AdLoadJobStatus::RUNNING;
        $this->startedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markCompleted(): void
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf(
                'Нельзя завершить задание в терминальном статусе: %s.',
                $this->status->value,
            ));
        }

        $this->status = AdLoadJobStatus::COMPLETED;
        $this->finishedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setChunksTotal(int $total): void
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf(
                'Нельзя установить chunksTotal на задание в терминальном статусе: %s.',
                $this->status->value,
            ));
        }

        Assert::greaterThanEq($total, 1, 'chunksTotal должен быть >= 1.');

        $this->chunksTotal = $total;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        if ($this->status->isTerminal()) {
            throw new \DomainException(sprintf(
                'Нельзя пометить неуспешным задание в терминальном статусе: %s.',
                $this->status->value,
            ));
        }

        Assert::notEmpty($reason, 'Причина ошибки не может быть пустой.');

        $this->status = AdLoadJobStatus::FAILED;
        $this->failureReason = $reason;
        $this->finishedAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Доля выполненных шагов (loaded + failed) относительно totalDays, 0..100.
     * Округление — до целого процента, чтобы UI не «дёргался» на долях.
     */
    public function getProgress(): int
    {
        if ($this->totalDays <= 0) {
            return 0;
        }

        $done = $this->loadedDays + $this->failedDays;
        $progress = (int) floor(($done * 100) / $this->totalDays);

        return min(100, max(0, $progress));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getMarketplace(): MarketplaceType
    {
        return $this->marketplace;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function getTotalDays(): int
    {
        return $this->totalDays;
    }

    public function getLoadedDays(): int
    {
        return $this->loadedDays;
    }

    public function getProcessedDays(): int
    {
        return $this->processedDays;
    }

    public function getFailedDays(): int
    {
        return $this->failedDays;
    }

    public function getChunksTotal(): int
    {
        return $this->chunksTotal;
    }

    public function getStatus(): AdLoadJobStatus
    {
        return $this->status;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
