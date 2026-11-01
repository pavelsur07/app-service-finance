<?php

declare(strict_types=1);

namespace App\Billing\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Цена тарифа за срок — готовая сумма, а не процент скидки. Уплаченная сумма
 * фиксируется в оплате, поэтому смена цены прошлые оплаты не меняет.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_tariff_prices')]
#[ORM\UniqueConstraint(name: 'uniq_billing_tariff_prices_tariff_months', columns: ['tariff_id', 'months'])]
class TariffPrice
{
    public const ALLOWED_MONTHS = [1, 3, 6, 12];

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Tariff::class)]
    #[ORM\JoinColumn(name: 'tariff_id', nullable: false)]
    private Tariff $tariff;

    #[ORM\Column]
    private int $months;

    #[ORM\Column(name: 'amount_kopecks')]
    private int $amountKopecks;

    public function __construct(Tariff $tariff, int $months, int $amountKopecks, ?string $id = null)
    {
        if (null !== $id) {
            Assert::uuid($id);
        }
        Assert::inArray($months, self::ALLOWED_MONTHS, 'Срок цены — 1, 3, 6 или 12 месяцев.');
        Assert::greaterThan($amountKopecks, 0, 'Цена тарифа должна быть больше нуля.');
        Assert::false($tariff->isPerpetual(), 'У бессрочного тарифа нет цены за срок.');

        $this->id = $id ?? Uuid::uuid7()->toString();
        $this->tariff = $tariff;
        $this->months = $months;
        $this->amountKopecks = $amountKopecks;
    }

    public function getId(): string
    {
        return $this->id;
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
}
