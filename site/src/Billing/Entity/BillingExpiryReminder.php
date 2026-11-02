<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Repository\BillingExpiryReminderRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Отметка «напоминание об окончании оплаты отправлено»: одна на (компания, «оплачено по», за сколько дней).
 * Unique-ключ держит «не больше одного письма» при повторе прогона; строки пишет
 * BillingExpiryReminderRepository::claim() через INSERT … ON CONFLICT DO NOTHING.
 */
#[ORM\Entity(repositoryClass: BillingExpiryReminderRepository::class)]
#[ORM\Table(name: 'billing_expiry_reminders')]
#[ORM\UniqueConstraint(name: 'uniq_billing_expiry_reminders_company_until_days', columns: ['company_id', 'paid_until', 'days_before'])]
class BillingExpiryReminder
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\Column(name: 'paid_until', type: 'date_immutable')]
    private \DateTimeImmutable $paidUntil;

    #[ORM\Column(name: 'days_before', type: 'smallint')]
    private int $daysBefore;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $companyId, \DateTimeImmutable $paidUntil, int $daysBefore, \DateTimeImmutable $now, ?string $id = null)
    {
        Assert::uuid($companyId);
        Assert::greaterThanEq($daysBefore, 0);
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->paidUntil = PaidPeriodCalculator::date($paidUntil);
        $this->daysBefore = $daysBefore;
        $this->createdAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getPaidUntil(): \DateTimeImmutable
    {
        return $this->paidUntil;
    }

    public function getDaysBefore(): int
    {
        return $this->daysBefore;
    }
}
