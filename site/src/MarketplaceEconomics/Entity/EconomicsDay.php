<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Пересчитанный день витрины и контрольные итоги: Σ строк (без себестоимости и справочных) =
 * `canon`; строки без SKU — `unallocated`. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'me_day')]
class EconomicsDay
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 32)]
    private string $channel;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $operationDate;

    #[ORM\Column(type: 'string', length: 32)]
    private string $rulesVersion;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $canonMinor;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $unallocatedMinor;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $costPriceMinor;

    /** Листингов с продажами без себестоимости на дату. */
    #[ORM\Column(type: 'integer')]
    private int $withoutCostPrice;

    #[ORM\Column(type: 'integer')]
    private int $lineCount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $refreshedAt;
}
