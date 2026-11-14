<?php

declare(strict_types=1);

namespace App\MarketplaceOzon\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Компоненты дня с кодом без решения: в канон не попали, сумма видна здесь. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'moz_quarantine')]
class OzonQuarantine
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
    #[ORM\Column(type: 'string', length: 128)]
    private string $sourceType;

    #[ORM\Column(type: 'money_amount_minor')]
    private int $amountMinor;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency;

    #[ORM\Column(type: 'integer')]
    private int $rowCount;

    /** @var list<string> */
    #[ORM\Column(type: 'jsonb')]
    private array $sourceRefs;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $quarantinedAt;
}
