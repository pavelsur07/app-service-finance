<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * План последовательной обработки батча рекламных отчётов Ozon Performance.
 *
 * Создаётся планировщиком одной транзакцией на весь {@see AdLoadJob}: батч —
 * это пара (подмножество кампаний ≤ 10, поддиапазон дат ≤ 62 дня), которую
 * cron-команды (POST → poll → download) обрабатывают строго по одному за тик.
 *
 * State machine: PLANNED → IN_FLIGHT → (OK | FAILED | ABANDONED). Переход
 * между состояниями — прерогатива cron-команд (см. Task-11.3+), Entity
 * предоставляет только сеттеры и геттеры; инварианты перехода пока живут
 * в вызывающем коде.
 *
 * Индексы (создаются в миграции Version20260423155717):
 *  - partial idx_asb_scheduler (scheduled_at) WHERE state='PLANNED';
 *  - partial idx_asb_poller (id) WHERE state='IN_FLIGHT';
 *  - idx_asb_job (job_id, state);
 *  - unique idx_asb_job_batch (job_id, batch_index).
 *
 * Partial-индексы не выражаются в Doctrine-атрибутах; отражены только в
 * миграции, поэтому в #[ORM\Index] объявлен лишь `idx_asb_job`.
 */
#[ORM\Entity(repositoryClass: AdScheduledBatchRepository::class)]
#[ORM\Table(name: 'marketplace_ad_scheduled_batches')]
#[ORM\Index(columns: ['job_id', 'state'], name: 'idx_asb_job')]
#[ORM\UniqueConstraint(name: 'idx_asb_job_batch', columns: ['job_id', 'batch_index'])]
#[ORM\HasLifecycleCallbacks]
class AdScheduledBatch
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $jobId;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 32, options: ['default' => 'ozon'])]
    private string $marketplace = 'ozon';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $campaignIds;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;

    #[ORM\Column(type: 'integer')]
    private int $batchIndex;

    #[ORM\Column(type: 'string', length: 32, enumType: AdScheduledBatchState::class, options: ['default' => 'PLANNED'])]
    private AdScheduledBatchState $state;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $ozonUuid = null;

    #[ORM\Column(type: 'string', length: 512, nullable: true)]
    private ?string $storagePath = null;

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $fileHash = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $campaignIds
     */
    public function __construct(
        string $id,
        string $jobId,
        string $companyId,
        array $campaignIds,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        int $batchIndex,
        \DateTimeImmutable $scheduledAt,
    ) {
        Assert::uuid($id);
        Assert::uuid($jobId);
        Assert::uuid($companyId);
        Assert::allString($campaignIds, 'Все campaignIds должны быть строками.');
        Assert::greaterThanEq($batchIndex, 0, 'batchIndex не может быть отрицательным.');

        // Нормализация до 00:00 — консистентно с AdLoadJob / AdChunkProgress:
        // без этого один и тот же батч с разными временами в одном поле попадает
        // мимо unique-guarantee на (job_id, batch_index) при переносе дат.
        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        if ($dateFrom > $dateTo) {
            throw new \DomainException('dateFrom не может быть позже dateTo.');
        }

        // UTC-нормализация: Postgres NOW() возвращает UTC, а колонка
        // `timestamp without time zone` хранит значение «как есть». Если писать
        // DateTimeImmutable в локальном TZ (Europe/Moscow +3), scheduler-cron
        // сравнивает его с UTC-NOW() и получает 3-часовой лаг (инцидент
        // Task-11.9a first prod run). См. ARCHITECTURE.md v1.28.
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('now', $utc);

        $this->id = $id;
        $this->jobId = $jobId;
        $this->companyId = $companyId;
        $this->campaignIds = array_values($campaignIds);
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->batchIndex = $batchIndex;
        $this->state = AdScheduledBatchState::PLANNED;
        $this->scheduledAt = $scheduledAt->setTimezone($utc);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Re-parse wall-clock как UTC после Doctrine hydration (Task-13a fix).
     *
     * Колонки `timestamp without time zone` хранят текст вида '2026-04-24 11:00:00'
     * без TZ. Doctrine `datetime_immutable`-тип гидратирует через
     * `DateTimeImmutable::createFromFormat('Y-m-d H:i:s', ...)` без явного TZ →
     * PHP применяет default TZ процесса (у scheduler-контейнера Europe/Moscow).
     * Результат: instant сдвинут на -3 часа относительно того, что Scheduler
     * записывал как UTC (`now()-3h` в Unix-секундах). `setTimezone('UTC')` тут
     * не помогает — он лишь меняет TZ-метку, instant остаётся сдвинутым.
     *
     * Приходится заново интерпретировать wall-clock: форматируем hydrated-
     * значение в строку 'Y-m-d H:i:s.u' (в PHP TZ это равно UTC wall-clock'у,
     * который и лежал в DB) и создаём новый DateTimeImmutable с явным UTC —
     * instant становится правильным, независимо от `date.timezone` процесса.
     *
     * Prod-инцидент 23–24.04.2026: batch'и переходили в ABANDONED за 30 сек
     * вместо 3-часового порога, потому что Poller видел hydrated-startedAt
     * «на 3 часа в прошлом» и `age > MAX_AGE_BEFORE_ABANDON_HOURS=3`.
     */
    #[ORM\PostLoad]
    public function normalizeTimezonesAfterLoad(): void
    {
        $utc = new \DateTimeZone('UTC');
        $fmt = 'Y-m-d H:i:s.u';

        $this->scheduledAt = new \DateTimeImmutable($this->scheduledAt->format($fmt), $utc);
        $this->startedAt = null === $this->startedAt
            ? null
            : new \DateTimeImmutable($this->startedAt->format($fmt), $utc);
        $this->finishedAt = null === $this->finishedAt
            ? null
            : new \DateTimeImmutable($this->finishedAt->format($fmt), $utc);
        $this->createdAt = new \DateTimeImmutable($this->createdAt->format($fmt), $utc);
        $this->updatedAt = new \DateTimeImmutable($this->updatedAt->format($fmt), $utc);
    }

    public function setState(AdScheduledBatchState $state): void
    {
        $this->state = $state;
        $this->markUpdatedAt();
    }

    public function setOzonUuid(?string $ozonUuid): void
    {
        $this->ozonUuid = $ozonUuid;
        $this->markUpdatedAt();
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): void
    {
        $this->startedAt = null === $startedAt
            ? null
            : $startedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->markUpdatedAt();
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): void
    {
        $this->finishedAt = null === $finishedAt
            ? null
            : $finishedAt->setTimezone(new \DateTimeZone('UTC'));
        $this->markUpdatedAt();
    }

    public function setStoragePath(?string $storagePath): void
    {
        $this->storagePath = $storagePath;
        $this->markUpdatedAt();
    }

    public function setFileHash(?string $fileHash): void
    {
        $this->fileHash = $fileHash;
        $this->markUpdatedAt();
    }

    public function setFileSize(?int $fileSize): void
    {
        if (null !== $fileSize && $fileSize < 0) {
            throw new \InvalidArgumentException('fileSize не может быть отрицательным.');
        }

        $this->fileSize = $fileSize;
        $this->markUpdatedAt();
    }

    public function setRetryCount(int $retryCount): void
    {
        if ($retryCount < 0) {
            throw new \InvalidArgumentException('retryCount не может быть отрицательным.');
        }

        $this->retryCount = $retryCount;
        $this->markUpdatedAt();
    }

    public function setLastError(?string $lastError): void
    {
        $this->lastError = $lastError;
        $this->markUpdatedAt();
    }

    public function setScheduledAt(\DateTimeImmutable $scheduledAt): void
    {
        $this->scheduledAt = $scheduledAt->setTimezone(new \DateTimeZone('UTC'));
        $this->markUpdatedAt();
    }

    public function markUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    public function getMarketplace(): string
    {
        return $this->marketplace;
    }

    /**
     * @return list<string>
     */
    public function getCampaignIds(): array
    {
        return $this->campaignIds;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function getBatchIndex(): int
    {
        return $this->batchIndex;
    }

    public function getState(): AdScheduledBatchState
    {
        return $this->state;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getOzonUuid(): ?string
    {
        return $this->ozonUuid;
    }

    public function getStoragePath(): ?string
    {
        return $this->storagePath;
    }

    public function getFileHash(): ?string
    {
        return $this->fileHash;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function getRetryCount(): int
    {
        return $this->retryCount;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
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
