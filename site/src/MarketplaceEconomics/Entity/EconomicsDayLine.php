<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Строка витрины экономики SKU: кабинет × день × листинг (пусто — без SKU) × показатель.
 * Пишется заменой дня через DBAL (`EconomicsStore`); entity — только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'me_day_line')]
#[ORM\Index(name: 'idx_me_day_line_company_date', columns: ['company_id', 'operation_date'])]
class EconomicsDayLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 32)]
    private string $channel;

    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $operationDate;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $listingId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $measure;

    #[ORM\Column(type: 'string', length: 16)]
    private string $level;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $amountMinor;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $quantity;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $allocationKey;
}
