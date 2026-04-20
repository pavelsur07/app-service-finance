<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use App\MarketplaceAds\Enum\OzonAdPendingReportState;
use App\MarketplaceAds\Repository\OzonAdPendingReportRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Персистентный след одного отчёта Ozon Performance, запрошенного через
 * POST /api/client/statistics.
 *
 * Создаётся {@see \App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient}
 * сразу после получения UUID от Ozon (ещё до первого polling-шага), чтобы
 * при любом последующем exception / таймауте / рестарте воркера сам UUID
 * и контекст запроса (companyId, диапазон дат, campaignIds, привязанный
 * AdLoadJob) не потерялись в локальной переменной PHP-процесса.
 *
 * На каждой итерации polling клиент обновляет state / lastCheckedAt /
 * pollAttempts / firstNonPendingAt — это даёт диагностику «почему отчёт
 * завис в NOT_STARTED 3 минуты» и служит базой для будущей логики
 * восстановления (задача 3: resume on Messenger retry).
 *
 * Колонка state — VARCHAR с канон-значениями из
 * {@see OzonAdPendingReportState}. Строка, а не enumType, чтобы Ozon-
 * специфичные значения (например, неизвестный state из будущего API)
 * можно было залогировать без миграции.
 */
#[ORM\Entity(repositoryClass: OzonAdPendingReportRepository::class)]
#[ORM\Table(name: 'marketplace_ad_pending_reports')]
#[ORM\Index(columns: ['company_id'], name: 'idx_ad_pending_report_company')]
#[ORM\Index(columns: ['job_id'], name: 'idx_ad_pending_report_job')]
#[ORM\Index(columns: ['state'], name: 'idx_ad_pending_report_state')]
#[ORM\UniqueConstraint(
    name: 'uq_ad_pending_report_ozon_uuid',
    columns: ['ozon_uuid'],
)]
class OzonAdPendingReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $ozonUuid;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $campaignIds;

    #[ORM\Column(type: 'string', length: 32)]
    private string $state;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $jobId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $firstNonPendingAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $pollAttempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $finalizedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param list<string> $campaignIds
     */
    public function __construct(
        string $companyId,
        string $ozonUuid,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        array $campaignIds,
        ?string $jobId = null,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($companyId);
        Assert::notEmpty($ozonUuid, 'ozonUuid не может быть пустым.');
        if (null !== $jobId) {
            Assert::uuid($jobId);
        }

        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        if ($dateFrom > $dateTo) {
            throw new \DomainException('dateFrom не может быть позже dateTo.');
        }

        $this->companyId = $companyId;
        $this->ozonUuid = $ozonUuid;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->campaignIds = array_values($campaignIds);
        $this->jobId = $jobId;
        $this->state = OzonAdPendingReportState::REQUESTED;
        $now = new \DateTimeImmutable();
        $this->requestedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOzonUuid(): string
    {
        return $this->ozonUuid;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    /**
     * @return list<string>
     */
    public function getCampaignIds(): array
    {
        return $this->campaignIds;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getJobId(): ?string
    {
        return $this->jobId;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getFirstNonPendingAt(): ?\DateTimeImmutable
    {
        return $this->firstNonPendingAt;
    }

    public function getPollAttempts(): int
    {
        return $this->pollAttempts;
    }

    public function getFinalizedAt(): ?\DateTimeImmutable
    {
        return $this->finalizedAt;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
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
