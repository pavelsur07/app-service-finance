<?php

declare(strict_types=1);

namespace App\MarketplaceEconomics\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Отметка «недельный отчёт отправлен»: одна на (компания, понедельник недели, канал доставки).
 * Первичный ключ держит «не больше одной отправки» при повторе прогона; строки пишет
 * EconomicsStore::claimWeeklyReport() через INSERT … ON CONFLICT DO NOTHING. Только схема.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'me_weekly_report')]
class EconomicsWeeklyReport
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $weekStart;

    /** email | telegram */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 16)]
    private string $delivery;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;
}
