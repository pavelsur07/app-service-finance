<?php

declare(strict_types=1);

namespace App\SourceFacts\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Текущая ревизия периода кабинета. Потребитель (переводчик канала) узнаёт об изменении,
 * сравнивая `currentRevision` с ревизией, которую он уже обработал.
 *
 * Только схема: пишет и читает `SourceFactStore` через DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'sf_period')]
#[ORM\UniqueConstraint(name: 'uq_sf_period', columns: ['company_id', 'connection_ref', 'resource_type', 'period_key'])]
class SourceFactPeriod
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 64)]
    private string $connectionRef;

    #[ORM\Column(type: 'string', length: 128)]
    private string $resourceType;

    #[ORM\Column(type: 'string', length: 32)]
    private string $periodKey;

    #[ORM\Column(type: 'integer')]
    private int $currentRevision;

    /** Число действующих записей текущей ревизии. */
    #[ORM\Column(type: 'integer')]
    private int $factCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;
}
