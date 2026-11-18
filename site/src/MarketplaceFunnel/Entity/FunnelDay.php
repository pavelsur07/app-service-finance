<?php

declare(strict_types=1);

namespace App\MarketplaceFunnel\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * День кабинета, переведённый в таблицу воронки: ревизия фактов, версия правил и контрольная сумма
 * строк (`control_sum` = Σ всех ступеней `mfn_row` дня). `available = false` — маркетплейс не отдал
 * день по тарифу кабинета: строк нет, это «нет данных», а не нули. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mfn_day')]
class FunnelDay
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

    #[ORM\Column(type: 'string', length: 16)]
    private string $channel;

    #[ORM\Column(type: 'integer')]
    private int $factsRevision;

    #[ORM\Column(type: 'string', length: 32)]
    private string $rulesVersion;

    #[ORM\Column(type: 'boolean')]
    private bool $available;

    #[ORM\Column(type: 'integer')]
    private int $rowCount;

    #[ORM\Column(type: 'bigint')]
    private int $controlSum;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $translatedAt;
}
