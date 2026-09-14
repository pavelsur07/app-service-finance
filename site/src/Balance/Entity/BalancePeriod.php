<?php

declare(strict_types=1);

namespace App\Balance\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

#[ORM\Entity]
#[ORM\Table(name: 'balance_periods')]
#[ORM\UniqueConstraint(name: 'uniq_balance_period_month', columns: ['company_id', 'month'])]
class BalancePeriod
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $companyId;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $month;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isClosed = false;

    #[ORM\Column(type: Types::GUID)]
    private string $changedBy;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $changedAt;

    public function __construct(string $companyId, \DateTimeImmutable $month, string $changedBy, string $reason = '')
    {
        Assert::uuid($companyId);
        $this->id = Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        Assert::same($month->format('d'), '01');
        Assert::uuid($changedBy);
        $this->month = $month;
        $this->changedBy = $changedBy;
        $this->reason = $reason;
        $this->changedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getMonth(): \DateTimeImmutable
    {
        return $this->month;
    }

    public function isClosed(): bool
    {
        return $this->isClosed;
    }

    public function getChangedBy(): string
    {
        return $this->changedBy;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }
}
