<?php

declare(strict_types=1);

namespace App\MarketplaceWb\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * День кабинета, переведённый в канон, и его контрольные итоги:
 * `total` (Σ компонентов строк отчёта без справочных) = `canon` + `quarantine`. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mwb_day')]
class WbTranslatedDay
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $operationDate;

    #[ORM\Column(type: 'integer')]
    private int $factsRevision;

    /** Ревизия рекламы дня (`wb.ads.daily`), с которой день переведён; 0 — без рекламы. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $adsRevision = 0;

    #[ORM\Column(type: 'string', length: 32)]
    private string $rulesVersion;

    #[ORM\Column(type: 'integer')]
    private int $factCount;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $totalMinor;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $canonMinor;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $quarantineMinor;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    /** Число операций с SKU, для которого не нашёлся листинг. */
    #[ORM\Column(type: 'integer')]
    private int $unresolvedListingCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $translatedAt;
}
