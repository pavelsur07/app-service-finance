<?php

declare(strict_types=1);

namespace App\MarketplaceOperations\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * День кабинета, операции которого заменены после последнего пересчёта витрины. Пишется в
 * той же транзакции, что операции; забирает витрина (этап 5).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'mo_dirty_window')]
class MarketplaceOperationDirtyWindow
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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $markedAt;
}
