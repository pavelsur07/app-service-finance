<?php

declare(strict_types=1);

namespace App\Ingestion\Entity;

use App\Ingestion\Domain\TenantOwnedInterface;
use App\Ingestion\Enum\PLDirtyPeriodReason;
use App\Ingestion\Enum\PLDirtyPeriodStatus;
use App\Ingestion\Repository\PLDirtyPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity(repositoryClass: PLDirtyPeriodRepository::class)]
#[ORM\Table(name: 'pnl_dirty_periods')]
#[ORM\UniqueConstraint(name: 'uniq_pdp_key', columns: ['company_id', 'period_year', 'period_month', 'shop_ref'])]
#[ORM\Index(name: 'idx_pdp_status_marked', columns: ['status', 'marked_at'])]
#[ORM\Index(name: 'idx_pdp_company_status', columns: ['company_id', 'status'])]
class PLDirtyPeriod implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $periodYear;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $periodMonth;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $shopRef;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: PLDirtyPeriodStatus::class, options: ['default' => 'pending'])]
    private PLDirtyPeriodStatus $status = PLDirtyPeriodStatus::PENDING;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: PLDirtyPeriodReason::class)]
    private PLDirtyPeriodReason $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $markedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6, nullable: true)]
    private ?\DateTimeImmutable $rebuiltAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, precision: 6)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $companyId,
        int $periodYear,
        int $periodMonth,
        string $shopRef,
        PLDirtyPeriodReason $reason,
    ) {
        Assert::uuid($companyId);
        Assert::range($periodYear, 2020, 2100);
        Assert::range($periodMonth, 1, 12);

        $now = new \DateTimeImmutable();

        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->periodYear = $periodYear;
        $this->periodMonth = $periodMonth;
        $this->shopRef = $shopRef;
        $this->reason = $reason;
        $this->markedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function markRebuilding(): void
    {
        $this->transitionTo(PLDirtyPeriodStatus::REBUILDING);

        ++$this->attempts;
        $this->lastError = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markDone(?\DateTimeImmutable $at = null): void
    {
        $this->transitionTo(PLDirtyPeriodStatus::DONE);

        $this->rebuiltAt = $at ?? new \DateTimeImmutable();
        $this->lastError = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markFailed(string $reason): void
    {
        Assert::notEmpty($reason);

        $this->transitionTo(PLDirtyPeriodStatus::FAILED);

        $this->lastError = $reason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markBlockedByClose(string $reason): void
    {
        Assert::notEmpty($reason);

        $this->transitionTo(PLDirtyPeriodStatus::BLOCKED_BY_CLOSE);

        $this->lastError = $reason;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function reopen(): void
    {
        $this->transitionTo(PLDirtyPeriodStatus::PENDING);

        $this->markedAt = new \DateTimeImmutable();
        $this->lastError = null;
        $this->updatedAt = $this->markedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getPeriodYear(): int
    {
        return $this->periodYear;
    }

    public function getPeriodMonth(): int
    {
        return $this->periodMonth;
    }

    public function getShopRef(): string
    {
        return $this->shopRef;
    }

    public function getStatus(): PLDirtyPeriodStatus
    {
        return $this->status;
    }

    public function getReason(): PLDirtyPeriodReason
    {
        return $this->reason;
    }

    public function getMarkedAt(): \DateTimeImmutable
    {
        return $this->markedAt;
    }

    public function getRebuiltAt(): ?\DateTimeImmutable
    {
        return $this->rebuiltAt;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
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

    private function transitionTo(PLDirtyPeriodStatus $next): void
    {
        if (!$this->status->canTransitionTo($next)) {
            throw new \DomainException(sprintf('Cannot transition P&L dirty period from "%s" to "%s".', $this->status->value, $next->value));
        }

        $this->status = $next;
    }
}
