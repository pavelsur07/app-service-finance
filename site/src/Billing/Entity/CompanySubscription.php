<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Exception\TariffChangeNotSupportedException;
use App\Billing\Repository\CompanySubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Текущий тариф компании и оплаченный период; одна строка на компанию (unique company_id).
 * `paidFrom` — начало непрерывно оплаченного отрезка, `paidUntil` — «оплачено по» включительно.
 * У бессрочного тарифа `paidUntil = null`. История — журнал `BillingPayment`.
 */
#[ORM\Entity(repositoryClass: CompanySubscriptionRepository::class)]
#[ORM\Table(name: 'billing_company_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_billing_company_subscriptions_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_billing_company_subscriptions_tariff', columns: ['tariff_id'])]
class CompanySubscription
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'guid')]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Tariff::class)]
    #[ORM\JoinColumn(name: 'tariff_id', nullable: false)]
    private Tariff $tariff;

    #[ORM\Column(name: 'paid_from', type: 'date_immutable')]
    private \DateTimeImmutable $paidFrom;

    #[ORM\Column(name: 'paid_until', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $paidUntil;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * Подписка, начатая оплатой платного тарифа.
     */
    public static function startPaid(string $companyId, Tariff $tariff, PaidPeriod $period, \DateTimeImmutable $now, ?string $id = null): self
    {
        Assert::false($tariff->isPerpetual(), 'Бессрочный тариф не оплачивается за период.');

        return new self($companyId, $tariff, $period->from, $period->until, $now, $id);
    }

    /**
     * Подписка на бессрочном тарифе («Друг»): без даты окончания.
     */
    public static function startPerpetual(string $companyId, Tariff $tariff, \DateTimeImmutable $from, \DateTimeImmutable $now, ?string $id = null): self
    {
        Assert::true($tariff->isPerpetual(), 'Тариф не бессрочный: нужна дата окончания.');

        return new self($companyId, $tariff, PaidPeriodCalculator::date($from), null, $now, $id);
    }

    private function __construct(
        string $companyId,
        Tariff $tariff,
        \DateTimeImmutable $paidFrom,
        ?\DateTimeImmutable $paidUntil,
        \DateTimeImmutable $now,
        ?string $id,
    ) {
        Assert::uuid($companyId);
        if (null !== $id) {
            Assert::uuid($id);
        }

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->companyId = $companyId;
        $this->tariff = $tariff;
        $this->paidFrom = $paidFrom;
        $this->paidUntil = $paidUntil;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * «Оплачено по», от которого продолжается новая оплата этого тарифа; null — новый период
     * начинается с дня зачисления (подписка истекла или тариф меняется с бессрочного/истёкшего).
     *
     * @throws TariffChangeNotSupportedException смена тарифа при действующем платном периоде
     */
    public function renewalBase(Tariff $tariff, \DateTimeImmutable $receivedAt): ?\DateTimeImmutable
    {
        if (null === $this->paidUntil) {
            return null;
        }

        $active = PaidPeriodCalculator::date($this->paidUntil) >= PaidPeriodCalculator::day($receivedAt);
        if ($tariff->getId() === $this->tariff->getId()) {
            return $active ? $this->paidUntil : null;
        }
        if ($active) {
            throw new TariffChangeNotSupportedException();
        }

        return null;
    }

    /**
     * Применяет оплаченный период: продолжение текущего отрезка или новый отрезок.
     */
    public function applyPaidPeriod(Tariff $tariff, PaidPeriod $period, \DateTimeImmutable $now): void
    {
        Assert::false($tariff->isPerpetual(), 'Бессрочный тариф не оплачивается за период.');

        $continues = null !== $this->paidUntil
            && $tariff->getId() === $this->tariff->getId()
            && $period->from->format('Y-m-d') === $this->paidUntil->modify('+1 day')->format('Y-m-d');

        if (!$continues) {
            $this->paidFrom = $period->from;
        }
        $this->tariff = $tariff;
        $this->paidUntil = $period->until;
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

    public function getTariff(): Tariff
    {
        return $this->tariff;
    }

    public function getPaidFrom(): \DateTimeImmutable
    {
        return $this->paidFrom;
    }

    public function getPaidUntil(): ?\DateTimeImmutable
    {
        return $this->paidUntil;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
