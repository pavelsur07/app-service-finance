<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Enum\PaymentSource;
use App\Billing\Repository\BillingPaymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Webmozart\Assert\Assert;

/**
 * Зачисленная оплата — неизменяемая запись журнала. `id` задаёт вызывающий: это ключ повтора
 * (скрытый UUIDv7 формы). Внешний ключ повтора — unique (source, external_id).
 */
#[ORM\Entity(repositoryClass: BillingPaymentRepository::class)]
#[ORM\Table(name: 'billing_payments')]
#[ORM\UniqueConstraint(name: 'uniq_billing_payments_source_external', columns: ['source', 'external_id'])]
#[ORM\Index(name: 'idx_billing_payments_company_received', columns: ['company_id', 'received_at'])]
#[ORM\Index(name: 'idx_billing_payments_tariff', columns: ['tariff_id'])]
class BillingPayment
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Tariff::class)]
    #[ORM\JoinColumn(name: 'tariff_id', nullable: false)]
    private Tariff $tariff;

    #[ORM\Column]
    private int $months;

    #[ORM\Column(name: 'amount_kopecks')]
    private int $amountKopecks;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'period_from', type: 'date_immutable')]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(name: 'period_until', type: 'date_immutable')]
    private \DateTimeImmutable $periodUntil;

    #[ORM\Column(length: 32, enumType: PaymentSource::class)]
    private PaymentSource $source;

    #[ORM\Column(name: 'external_id', length: 128, nullable: true)]
    private ?string $externalId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $id,
        string $companyId,
        Tariff $tariff,
        int $months,
        int $amountKopecks,
        \DateTimeImmutable $receivedAt,
        PaidPeriod $period,
        PaymentSource $source,
        ?string $externalId,
        \DateTimeImmutable $now,
    ) {
        Assert::uuid($id);
        Assert::uuid($companyId);
        Assert::greaterThanEq($months, 1, 'Срок оплаты — не меньше месяца.');
        Assert::greaterThanEq($amountKopecks, 0, 'Сумма оплаты не может быть отрицательной.');
        Assert::false($tariff->isPerpetual(), 'Бессрочный тариф не оплачивается за период.');
        if (null !== $externalId) {
            Assert::stringNotEmpty(trim($externalId), 'Внешний ID платежа не может быть пустым.');
            Assert::maxLength($externalId, 128);
        }

        $this->id = $id;
        $this->companyId = $companyId;
        $this->tariff = $tariff;
        $this->months = $months;
        $this->amountKopecks = $amountKopecks;
        // Колонка без зоны: храним московское время, иначе момент из другой зоны сдвинется при чтении.
        $this->receivedAt = $receivedAt->setTimezone(new \DateTimeZone(PaidPeriodCalculator::TIMEZONE));
        $this->periodFrom = $period->from;
        $this->periodUntil = $period->until;
        $this->source = $source;
        $this->externalId = $externalId;
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

    public function getTariff(): Tariff
    {
        return $this->tariff;
    }

    public function getMonths(): int
    {
        return $this->months;
    }

    public function getAmountKopecks(): int
    {
        return $this->amountKopecks;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodUntil(): \DateTimeImmutable
    {
        return $this->periodUntil;
    }

    public function getSource(): PaymentSource
    {
        return $this->source;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }
}
