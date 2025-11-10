<?php

namespace App\Telegram\Entity;

use App\Entity\Company;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'report_subscriptions')]
#[ORM\UniqueConstraint(
    name: 'uniq_report_subscrib_company_user',
    columns: ['company_id', 'telegram_user_id']
)]
class ReportSubscription
{
    public const PERIOD_DAILY = 'daily';
    public const PERIOD_WEEKLY = 'weekly';
    public const PERIOD_MONTHLY = 'monthly';

    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: TelegramUser::class)]
    #[ORM\JoinColumn(name: 'telegram_user_id', nullable: false, onDelete: 'CASCADE')]
    private TelegramUser $telegramUser;

    // daily / weekly / monthly
    #[ORM\Column(type: 'string', length: 16)]
    private string $periodicity;

    // Local time to send report (HH:MM). Doctrine 'time' is stored as datetime in some drivers; using string for portability.
    #[ORM\Column(type: 'string', length: 5)]
    private string $sendAtLocalTime; // '09:00'

    // IANA tz name (fallback: company's)
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $timezone = null;

    // Bitmask for selected metrics (simple MVP int)
    #[ORM\Column(type: 'integer')]
    private int $metricsMask = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $isEnabled = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Company $company, TelegramUser $telegramUser, string $periodicity, string $sendAtLocalTime)
    {
        $this->company = $company;
        $this->telegramUser = $telegramUser;
        $this->periodicity = $periodicity;
        $this->sendAtLocalTime = $sendAtLocalTime;
        $this->isEnabled = true;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function disable(): void
    {
        $this->isEnabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function enable(): void
    {
        $this->isEnabled = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setMetricsMask(int $mask): void
    {
        $this->metricsMask = $mask;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setTimezone(?string $tz): void
    {
        $this->timezone = $tz;
        $this->updatedAt = new \DateTimeImmutable();
    }

    // getters/setters ...
}
