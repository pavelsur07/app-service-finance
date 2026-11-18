<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Воронка товара за день: товар — идентификатор маркетплейса (SKU Ozon, nmId WB), листинг находит
 * читатель; `source` — источник трафика (`total` — всего). Ступень без данных — NULL. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mfn_row')]
#[ORM\Index(name: 'idx_mfn_row_company_date', columns: ['company_id', 'operation_date'])]
class FunnelDayRow
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

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    private string $marketplaceSku;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 16)]
    private string $source;

    #[ORM\Column(type: 'string', length: 16)]
    private string $channel;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $impressions = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $impressionsSearch = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $cardViews = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $cartAdds = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $orders = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $revenueMinor = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $buyouts = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $cancels = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $returns = null;
}
