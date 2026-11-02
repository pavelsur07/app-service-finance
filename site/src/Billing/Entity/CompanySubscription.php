<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use App\Billing\Domain\PaidPeriod;
use App\Billing\Domain\PaidPeriodCalculator;
use App\Billing\Domain\PaymentPlan;
use App\Billing\Domain\PaymentPlanKind;
use App\Billing\Repository\CompanySubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Текущий тариф компании и оплаченный период; одна строка на компанию (unique company_id).
 * `paidFrom` — начало непрерывно оплаченного отрезка, `paidUntil` — «оплачено по» включительно.
 * У бессрочного тарифа `paidUntil = null`. История — журнал `BillingPayment`.
 *
 * Понижение тарифа планируется: `nextTariff` начнёт действовать с `nextTariffFrom` (день после
 * «оплачено по» на момент понижения), до тех пор действует `tariff`. Действующий на дату тариф —
 * `tariffOn()`; `getTariff()` — сохранённый, без учёта запланированного перехода.
 */
#[ORM\Entity(repositoryClass: CompanySubscriptionRepository::class)]
#[ORM\Table(name: 'billing_company_subscriptions')]
#[ORM\UniqueConstraint(name: 'uniq_billing_company_subscriptions_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_billing_company_subscriptions_tariff', columns: ['tariff_id'])]
#[ORM\Index(name: 'idx_billing_company_subscriptions_next_tariff', columns: ['next_tariff_id'])]
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

    /** Пробный период: тариф без оплаты до `paidUntil`; снимается оплатой или назначением тарифа. */
    #[ORM\Column(name: 'is_trial', type: 'boolean', options: ['default' => false])]
    private bool $trial = false;

    #[ORM\ManyToOne(targetEntity: Tariff::class)]
    #[ORM\JoinColumn(name: 'next_tariff_id', nullable: true)]
    private ?Tariff $nextTariff = null;

    #[ORM\Column(name: 'next_tariff_from', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $nextTariffFrom = null;

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
     * Пробный период нового кабинета: платный тариф без оплаты на заданный срок.
     */
    public static function startTrial(string $companyId, Tariff $tariff, PaidPeriod $period, \DateTimeImmutable $now, ?string $id = null): self
    {
        Assert::false($tariff->isPerpetual(), 'Пробный период — только на тарифе с датой окончания.');

        $subscription = new self($companyId, $tariff, $period->from, $period->until, $now, $id);
        $subscription->trial = true;

        return $subscription;
    }

    /**
     * Подписка на бессрочном тарифе («Друг»): без даты окончания.
     */
    public static function startPerpetual(string $companyId, Tariff $tariff, \DateTimeImmutable $from, \DateTimeImmutable $now, ?string $id = null): self
    {
        Assert::true($tariff->isPerpetual(), 'Тариф не бессрочный: нужна дата окончания.');

        return new self($companyId, $tariff, PaidPeriodCalculator::date($from), null, $now, $id);
    }

    /**
     * Подписка, назначенная администратором: бессрочный тариф — без даты окончания,
     * остальные — с явным периодом (договор сопровождения, аутсорсер, ручная правка).
     */
    public static function assignNew(string $companyId, Tariff $tariff, \DateTimeImmutable $from, ?\DateTimeImmutable $until, \DateTimeImmutable $now, ?string $id = null): self
    {
        [$from, $until] = self::assignedPeriod($tariff, $from, $until);

        return new self($companyId, $tariff, $from, $until, $now, $id);
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
     * Тариф, действующий в день `$day`: запланированный — с даты перехода.
     */
    public function tariffOn(\DateTimeImmutable $day): Tariff
    {
        if (null !== $this->nextTariff && null !== $this->nextTariffFrom
            && PaidPeriodCalculator::date($day) >= PaidPeriodCalculator::date($this->nextTariffFrom)) {
            return $this->nextTariff;
        }

        return $this->tariff;
    }

    /**
     * Начало текущего отрезка на день `$day`: с наступлением перехода — дата перехода.
     */
    public function paidFromOn(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $this->tariffOn($day) !== $this->tariff && null !== $this->nextTariffFrom ? $this->nextTariffFrom : $this->paidFrom;
    }

    /**
     * Запланированный, но ещё не начавшийся к дню `$day` тариф; null — перехода нет или он уже наступил.
     */
    public function scheduledTariffAfter(\DateTimeImmutable $day): ?Tariff
    {
        if (null === $this->nextTariff || null === $this->nextTariffFrom
            || PaidPeriodCalculator::date($day) >= PaidPeriodCalculator::date($this->nextTariffFrom)) {
            return null;
        }

        return $this->nextTariff;
    }

    /**
     * Применяет план оплаты (`PaymentPlanner`), рассчитанный на день зачисления `$receivedAt`.
     * Наступивший переход сначала становится текущим тарифом. Первая оплата после пробного
     * периода начинает оплаченный отрезок и снимает признак пробного.
     */
    public function applyPlan(Tariff $tariff, PaymentPlan $plan, \DateTimeImmutable $receivedAt, \DateTimeImmutable $now): void
    {
        Assert::false($tariff->isPerpetual(), 'Бессрочный тариф не оплачивается за период.');
        $this->promoteScheduled(PaidPeriodCalculator::day($receivedAt));
        $period = $plan->period;

        switch ($plan->kind) {
            case PaymentPlanKind::NEW_PERIOD:
            case PaymentPlanKind::UPGRADE:
                $this->tariff = $tariff;
                $this->paidFrom = $period->from;
                $this->clearScheduled();
                break;
            case PaymentPlanKind::RENEWAL:
                if (null !== $this->nextTariff) {
                    Assert::same($tariff->getId(), $this->nextTariff->getId(), 'При запланированном переходе продлевается только запланированный тариф.');
                } else {
                    if ($this->trial) {
                        $this->paidFrom = $period->from;
                    } else {
                        Assert::same($tariff->getId(), $this->tariff->getId(), 'Продление — только текущего тарифа.');
                    }
                    $this->tariff = $tariff;
                }
                break;
            case PaymentPlanKind::DOWNGRADE:
                Assert::null($this->nextTariff, 'Переход уже запланирован.');
                $this->nextTariff = $tariff;
                $this->nextTariffFrom = $period->from;
                break;
        }

        $this->paidUntil = $period->until;
        $this->trial = false;
        $this->updatedAt = $now;
    }

    /**
     * Отмена запланированного перехода администратором: до «оплачено по» остаётся текущий тариф.
     * Переход должен быть и ещё не наступить к дню `$day`.
     */
    public function cancelScheduled(\DateTimeImmutable $day, \DateTimeImmutable $now): void
    {
        Assert::notNull($this->scheduledTariffAfter($day), 'Запланированного перехода нет или он уже наступил.');

        $this->clearScheduled();
        $this->updatedAt = $now;
    }

    private function promoteScheduled(\DateTimeImmutable $day): void
    {
        if (null === $this->nextTariff || null === $this->nextTariffFrom || $day < PaidPeriodCalculator::date($this->nextTariffFrom)) {
            return;
        }

        $this->tariff = $this->nextTariff;
        $this->paidFrom = $this->nextTariffFrom;
        $this->clearScheduled();
    }

    private function clearScheduled(): void
    {
        $this->nextTariff = null;
        $this->nextTariffFrom = null;
    }

    /**
     * Назначение тарифа администратором поверх текущей подписки.
     */
    public function assign(Tariff $tariff, \DateTimeImmutable $from, ?\DateTimeImmutable $until, \DateTimeImmutable $now): void
    {
        [$this->paidFrom, $this->paidUntil] = self::assignedPeriod($tariff, $from, $until);
        $this->tariff = $tariff;
        $this->clearScheduled();
        $this->trial = false;
        $this->updatedAt = $now;
    }

    /**
     * @return array{\DateTimeImmutable, ?\DateTimeImmutable}
     */
    private static function assignedPeriod(Tariff $tariff, \DateTimeImmutable $from, ?\DateTimeImmutable $until): array
    {
        if ($tariff->isPerpetual()) {
            Assert::null($until, 'У бессрочного тарифа нет даты окончания.');

            return [PaidPeriodCalculator::date($from), null];
        }

        Assert::notNull($until, 'Для тарифа с периодом нужна дата окончания.');
        $period = new PaidPeriod($from, $until);

        return [$period->from, $period->until];
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

    public function getNextTariff(): ?Tariff
    {
        return $this->nextTariff;
    }

    public function getNextTariffFrom(): ?\DateTimeImmutable
    {
        return $this->nextTariffFrom;
    }

    public function isTrial(): bool
    {
        return $this->trial;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
